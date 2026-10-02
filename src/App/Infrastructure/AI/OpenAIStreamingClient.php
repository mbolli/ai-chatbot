<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\StreamEnd;
use App\Domain\Service\Stream\TextDelta;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;
use App\Domain\Service\Stream\Usage;
use App\Infrastructure\AI\Tools\ToolInterface;

/**
 * Streaming client for the OpenAI Chat Completions API.
 *
 * Runs the tool loop itself: each tool_calls response is answered with the tool results
 * in a continuation request, up to $maxSteps requests per call.
 */
final class OpenAIStreamingClient {
    private const string API_HOST = 'api.openai.com';
    private const int DEFAULT_MAX_TOKENS = 4096;
    private const int DEFAULT_MAX_STEPS = 5;

    /**
     * Reasoning models reject function tools on /v1/chat/completions unless reasoning is off.
     * gpt-6-astra and gpt-6.1-sol do not accept 'none' and need the Responses API instead.
     */
    private const array NO_REASONING_MODELS = ['gpt-6-sol', 'gpt-6-luna', 'gpt-5.6-terra', 'gpt-5.6-luna'];

    /** @var list<ToolInterface> */
    private array $tools = [];

    public function __construct(
        private readonly string $apiKey,
        private readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
        private readonly int $maxSteps = self::DEFAULT_MAX_STEPS,
        private readonly SseTransport $transport = new SseHttpTransport(self::API_HOST),
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
    ) {}

    /**
     * @param list<ToolInterface> $tools
     */
    public function setTools(array $tools): void {
        $this->tools = $tools;
    }

    /**
     * @param array<array{role: string, content: string}> $messages Conversation messages
     * @param string                                      $model    Model ID (e.g., gpt-6-luna)
     * @param null|string                                 $system   Optional system prompt
     *
     * @return \Generator<int, StreamEnd|TextDelta|ToolCall|ToolResult> Ends with exactly one StreamEnd
     *
     * @throws \RuntimeException On API errors with descriptive message
     */
    public function streamChatRealtime(array $messages, string $model, ?string $system = null): \Generator {
        /** @var list<array<string, mixed>> $formatted */
        $formatted = [];
        if ($system !== null) {
            $formatted[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($messages as $message) {
            $formatted[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        $usage = new Usage();

        for ($step = 1;; ++$step) {
            $response = yield from $this->request($this->buildPayload($model, $formatted));
            $usage = $usage->add($response['usage']);

            if ($response['finish_reason'] !== 'tool_calls' || $response['tool_calls'] === []) {
                yield new StreamEnd(self::mapStopReason($response['finish_reason']), $usage);

                return;
            }

            if ($step >= $this->maxSteps) {
                yield new StreamEnd(StopReason::ToolLimit, $usage);

                return;
            }

            $formatted[] = [
                'role' => 'assistant',
                'content' => $response['content'] !== '' ? $response['content'] : null,
                'tool_calls' => array_map(static fn (array $call): array => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
                ], $response['tool_calls']),
            ];

            foreach ((yield from $this->executeToolCalls($response['tool_calls'])) as $toolMessage) {
                $formatted[] = $toolMessage;
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return \Generator<int, TextDelta, mixed, array{content: string, tool_calls: list<array{id: string, name: string, arguments: string}>, finish_reason: string, usage: Usage}>
     */
    private function request(array $payload): \Generator {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            return yield from $this->retryPolicy->run($this->attempt(...), $body);
        } catch (TransportException $e) {
            throw new \RuntimeException(
                $e->status === null
                    ? 'Failed to connect to OpenAI API: ' . $e->getMessage()
                    : "HTTP error from AI engine ({$e->status}): {$e->body}",
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * One HTTP request: streams text, returns the accumulated text and tool calls.
     *
     * @return \Generator<int, TextDelta, mixed, array{content: string, tool_calls: list<array{id: string, name: string, arguments: string}>, finish_reason: string, usage: Usage}>
     *
     * @throws TransportException
     */
    private function attempt(string $body): \Generator {
        $events = $this->transport->stream('/v1/chat/completions', ['Authorization' => "Bearer {$this->apiKey}"], $body);

        $content = '';

        /** @var array<int, array{id: string, name: string, arguments: string}> $toolCalls */
        $toolCalls = [];
        $finishReason = null;
        $done = false;
        $usage = new Usage();

        foreach ($events as $sse) {
            if ($sse->data === '[DONE]') {
                $done = true;

                break;
            }

            try {
                $event = json_decode($sse->data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($event)) {
                continue;
            }

            if (isset($event['error'])) {
                throw new TransportException('stream error', 500, $sse->data);
            }

            // With include_usage the last chunk carries usage and no choices
            if (\is_array($event['usage'] ?? null)) {
                $cached = (int) ($event['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);
                // prompt_tokens includes cached tokens (Anthropic's input_tokens does not), report uncached input for both
                $usage = new Usage(
                    inputTokens: max(0, (int) ($event['usage']['prompt_tokens'] ?? 0) - $cached),
                    outputTokens: (int) ($event['usage']['completion_tokens'] ?? 0),
                    cacheReadTokens: $cached,
                );
            }

            $choice = $event['choices'][0] ?? null;
            if (!\is_array($choice)) {
                continue;
            }

            $delta = (array) ($choice['delta'] ?? []);

            $text = (string) ($delta['content'] ?? '');
            if ($text !== '') {
                $content .= $text;

                yield new TextDelta($text);
            }

            foreach ((array) ($delta['tool_calls'] ?? []) as $toolCallDelta) {
                $index = (int) ($toolCallDelta['index'] ?? 0);
                $call = $toolCalls[$index] ?? ['id' => '', 'name' => '', 'arguments' => ''];

                if (isset($toolCallDelta['id'])) {
                    $call['id'] = (string) $toolCallDelta['id'];
                }
                $call['name'] .= (string) ($toolCallDelta['function']['name'] ?? '');
                $call['arguments'] .= (string) ($toolCallDelta['function']['arguments'] ?? '');
                $toolCalls[$index] = $call;
            }

            if (isset($choice['finish_reason'])) {
                $finishReason = (string) $choice['finish_reason'];
            }
        }

        if ($finishReason === null && !$done) {
            throw new TransportException('Stream from ' . self::API_HOST . ' ended before the response was complete');
        }

        ksort($toolCalls);

        return [
            'content' => $content,
            'tool_calls' => array_values($toolCalls),
            'finish_reason' => $finishReason ?? '',
            'usage' => $usage,
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $model, array $messages): array {
        $payload = [
            'model' => $model,
            'max_completion_tokens' => $this->maxTokens,
            'messages' => $messages,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        $tools = $this->buildToolsArray();
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        if (\in_array($model, self::NO_REASONING_MODELS, true)) {
            $payload['reasoning_effort'] = 'none';
        }

        return $payload;
    }

    private static function mapStopReason(string $finishReason): StopReason {
        return match ($finishReason) {
            'stop', 'tool_calls' => StopReason::EndTurn,
            'length' => StopReason::MaxTokens,
            'content_filter' => StopReason::Refusal,
            default => StopReason::Unknown,
        };
    }

    /**
     * @param list<array{id: string, name: string, arguments: string}> $toolCalls
     *
     * @return \Generator<int, ToolCall|ToolResult, mixed, list<array{role: string, tool_call_id: string, content: string}>>
     */
    private function executeToolCalls(array $toolCalls): \Generator {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $name = $toolCall['name'];
            $decoded = json_decode($toolCall['arguments'], true);
            $input = \is_array($decoded) ? $decoded : [];

            yield new ToolCall($toolCall['id'], $name, $input);

            $tool = array_find($this->tools, static fn (ToolInterface $t): bool => $t->name() === $name);

            try {
                $content = $tool?->execute($input) ?? "Error: Unknown tool '{$name}'";
            } catch (\Throwable $e) {
                $content = 'Error: ' . $e->getMessage();
            }

            yield new ToolResult($toolCall['id'], $name, $content, str_starts_with($content, 'Error:'));

            $results[] = ['role' => 'tool', 'tool_call_id' => $toolCall['id'], 'content' => $content];
        }

        return $results;
    }

    /**
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    private function buildToolsArray(): array {
        return array_map(static fn (ToolInterface $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->inputSchema(),
            ],
        ], $this->tools);
    }
}
