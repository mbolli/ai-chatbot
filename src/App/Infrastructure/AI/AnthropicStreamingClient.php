<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\StreamEnd;
use App\Domain\Service\Stream\TextDelta;
use App\Domain\Service\Stream\ThinkingDelta;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;
use App\Domain\Service\Stream\Usage;
use App\Infrastructure\AI\Tools\ToolInterface;

/**
 * Streaming client for the Anthropic Messages API.
 *
 * Runs the tool loop itself: each tool_use response is answered with the tool results
 * in a continuation request, up to $maxSteps requests per call.
 */
final class AnthropicStreamingClient {
    private const string API_HOST = 'api.anthropic.com';
    private const string API_VERSION = '2023-06-01';
    private const int DEFAULT_MAX_TOKENS = 4096;
    private const int DEFAULT_MAX_STEPS = 5;
    private const array LOW_EFFORT_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    /**
     * These models always think; without display "summarized" their thinking deltas are empty.
     */
    private const array SUMMARIZED_THINKING_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    /**
     * HTTP status equivalent of the error types an SSE error event can carry, for the retry decision.
     */
    private const array ERROR_STATUSES = [
        'invalid_request_error' => 400,
        'authentication_error' => 401,
        'permission_error' => 403,
        'not_found_error' => 404,
        'request_too_large' => 413,
        'rate_limit_error' => 429,
        'api_error' => 500,
        'timeout_error' => 504,
        'overloaded_error' => 529,
    ];

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
     * @param string                                      $model    Model ID (e.g., claude-haiku-4-5)
     * @param null|string                                 $system   Optional system prompt
     *
     * @return \Generator<int, StreamEnd|TextDelta|ThinkingDelta|ToolCall|ToolResult> Ends with exactly one StreamEnd
     *
     * @throws \RuntimeException On API errors with descriptive message
     */
    public function streamChatRealtime(array $messages, string $model, ?string $system = null): \Generator {
        $messages = $this->formatMessages($messages);
        $usage = new Usage();

        for ($step = 1;; ++$step) {
            $response = yield from $this->request($this->buildPayload($model, $messages, $system));
            $usage = $usage->add($response['usage']);

            $toolUses = array_values(array_filter($response['content'], static fn (array $b): bool => ($b['type'] ?? '') === 'tool_use'));

            // A tool_use block cut off by max_tokens has incomplete input, so only a tool_use stop runs tools
            if ($response['stop_reason'] !== 'tool_use' || $toolUses === []) {
                yield new StreamEnd(self::mapStopReason($response['stop_reason']), $usage);

                return;
            }

            if ($step >= $this->maxSteps) {
                yield new StreamEnd(StopReason::ToolLimit, $usage);

                return;
            }

            $toolResults = yield from $this->executeToolCalls($toolUses);

            // Echo the assistant turn back unchanged (thinking blocks must keep their signatures)
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return \Generator<int, TextDelta|ThinkingDelta, mixed, array{content: list<array<string, mixed>>, stop_reason: string, usage: Usage}>
     */
    private function request(array $payload): \Generator {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            return yield from $this->retryPolicy->run($this->attempt(...), $body);
        } catch (TransportException $e) {
            throw new \RuntimeException($this->describeError($e), $e->getCode(), $e);
        }
    }

    /**
     * One HTTP request: streams text and thinking, returns the complete assistant content.
     *
     * @return \Generator<int, TextDelta|ThinkingDelta, mixed, array{content: list<array<string, mixed>>, stop_reason: string, usage: Usage}>
     *
     * @throws TransportException
     */
    private function attempt(string $body): \Generator {
        $events = $this->transport->stream('/v1/messages', [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
        ], $body);

        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = [];

        /** @var array<int, string> $toolInputJson */
        $toolInputJson = [];

        /** @var array<string, mixed> $usage */
        $usage = [];
        $stopReason = null;

        foreach ($events as $sse) {
            try {
                $event = json_decode($sse->data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($event)) {
                continue;
            }

            $type = (string) ($event['type'] ?? '');
            $index = (int) ($event['index'] ?? 0);

            if ($type === 'content_block_delta' && isset($blocks[$index])) {
                $delta = (array) ($event['delta'] ?? []);
                $deltaType = (string) ($delta['type'] ?? '');

                if ($deltaType === 'text_delta' || $deltaType === 'thinking_delta') {
                    $field = $deltaType === 'text_delta' ? 'text' : 'thinking';
                    $text = (string) ($delta[$field] ?? '');
                    $blocks[$index][$field] = (string) ($blocks[$index][$field] ?? '') . $text;

                    if ($text !== '') {
                        yield $deltaType === 'text_delta' ? new TextDelta($text) : new ThinkingDelta($text);
                    }
                } elseif ($deltaType === 'signature_delta') {
                    $blocks[$index]['signature'] = (string) ($delta['signature'] ?? '');
                } elseif ($deltaType === 'input_json_delta') {
                    $toolInputJson[$index] = ($toolInputJson[$index] ?? '') . (string) ($delta['partial_json'] ?? '');
                }
            } elseif ($type === 'content_block_start') {
                $blocks[$index] = (array) ($event['content_block'] ?? []);
            } elseif ($type === 'content_block_stop' && ($blocks[$index]['type'] ?? '') === 'tool_use') {
                try {
                    $input = json_decode(($toolInputJson[$index] ?? '') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $input = [];
                }
                // An empty PHP array would encode as [], the API expects an object
                $blocks[$index]['input'] = \is_array($input) && $input !== [] ? $input : new \stdClass();
            } elseif ($type === 'message_start') {
                $usage = (array) ($event['message']['usage'] ?? []);
            } elseif ($type === 'message_delta') {
                $stopReason = (string) ($event['delta']['stop_reason'] ?? '');
                // Usage here is cumulative for the request and supersedes message_start
                $usage = array_merge($usage, array_filter((array) ($event['usage'] ?? []), static fn (mixed $v): bool => $v !== null));
            } elseif ($type === 'message_stop') {
                break;
            } elseif ($type === 'error') {
                $errorType = (string) ($event['error']['type'] ?? '');

                throw new TransportException("stream error: {$errorType}", self::ERROR_STATUSES[$errorType] ?? 500, $sse->data);
            }
        }

        if ($stopReason === null) {
            throw new TransportException('Stream from ' . self::API_HOST . ' ended before the response was complete');
        }

        ksort($blocks);

        return [
            // The API rejects empty text blocks when the turn is echoed back
            'content' => array_values(array_filter($blocks, static fn (array $b): bool => ($b['type'] ?? '') !== 'text' || ($b['text'] ?? '') !== '')),
            'stop_reason' => $stopReason,
            'usage' => new Usage(
                inputTokens: (int) ($usage['input_tokens'] ?? 0),
                outputTokens: (int) ($usage['output_tokens'] ?? 0),
                cacheReadTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
                cacheWriteTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            ),
        ];
    }

    /**
     * Keys stay in the same order and nothing varies per request, so the tools + system + messages
     * prefix is byte-stable across turns and the automatic cache breakpoint can hit.
     *
     * @param list<array{role: string, content: array<mixed>|string}> $messages
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $model, array $messages, ?string $system): array {
        $payload = [
            'model' => $model,
            'max_tokens' => $this->maxTokens,
            'cache_control' => ['type' => 'ephemeral'],
        ];

        $tools = $this->buildToolsArray();
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        if ($system !== null) {
            $payload['system'] = $system;
        }

        $payload['messages'] = $messages;

        if (\in_array($model, self::SUMMARIZED_THINKING_MODELS, true)) {
            $payload['thinking'] = ['type' => 'adaptive', 'display' => 'summarized'];
        }

        // These models always think, and thinking tokens count against max_tokens
        if (\in_array($model, self::LOW_EFFORT_MODELS, true)) {
            $payload['output_config'] = ['effort' => 'low'];
        }

        $payload['stream'] = true;

        return $payload;
    }

    private static function mapStopReason(string $reason): StopReason {
        return match ($reason) {
            'end_turn', 'tool_use' => StopReason::EndTurn,
            'max_tokens', 'model_context_window_exceeded' => StopReason::MaxTokens,
            'refusal' => StopReason::Refusal,
            'stop_sequence' => StopReason::StopSequence,
            default => StopReason::Unknown,
        };
    }

    private function describeError(TransportException $e): string {
        if ($e->status === null) {
            return 'connection error: ' . $e->getMessage();
        }

        try {
            $data = json_decode($e->body, true, 512, JSON_THROW_ON_ERROR);
            $message = \is_array($data) ? (string) ($data['error']['message'] ?? "HTTP {$e->status}") : "HTTP {$e->status}";
        } catch (\JsonException) {
            $message = "HTTP {$e->status}";
        }

        return match ($e->status) {
            401 => 'invalid_api_key: ' . $message,
            429 => 'rate limit exceeded: ' . $message,
            529 => 'overloaded: Anthropic API is overloaded',
            408 => 'timeout: Request timed out',
            default => $e->status >= 500
                ? "server error (HTTP {$e->status}): {$message}"
                : "API error (HTTP {$e->status}): {$message}",
        };
    }

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    private function buildToolsArray(): array {
        return array_map(static fn (ToolInterface $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->inputSchema(),
        ], $this->tools);
    }

    /**
     * @param list<array<string, mixed>> $toolUses tool_use content blocks
     *
     * @return \Generator<int, ToolCall|ToolResult, mixed, list<array{type: string, tool_use_id: string, content: string, is_error: bool}>>
     */
    private function executeToolCalls(array $toolUses): \Generator {
        $results = [];

        foreach ($toolUses as $toolUse) {
            $id = (string) $toolUse['id'];
            $name = (string) $toolUse['name'];
            $input = (array) $toolUse['input'];

            yield new ToolCall($id, $name, $input);

            $tool = array_find($this->tools, static fn (ToolInterface $t): bool => $t->name() === $name);

            try {
                $content = $tool?->execute($input) ?? "Error: Unknown tool '{$name}'";
            } catch (\Throwable $e) {
                $content = 'Error: ' . $e->getMessage();
            }
            $isError = str_starts_with($content, 'Error:');

            yield new ToolResult($id, $name, $content, $isError);

            $results[] = ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $content, 'is_error' => $isError];
        }

        return $results;
    }

    /**
     * @param array<array{role: string, content: array<mixed>|string}> $messages
     *
     * @return list<array{role: string, content: array<mixed>|string}>
     */
    private function formatMessages(array $messages): array {
        $formatted = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];

            if ($role === 'system') {
                continue;
            }

            if (!\in_array($role, ['user', 'assistant'], true)) {
                $role = 'user';
            }

            $formatted[] = [
                'role' => $role,
                'content' => $msg['content'],
            ];
        }

        // Anthropic requires messages to start with a user message
        if (!empty($formatted) && $formatted[0]['role'] !== 'user') {
            array_unshift($formatted, [
                'role' => 'user',
                'content' => 'Hello',
            ]);
        }

        return $formatted;
    }
}
