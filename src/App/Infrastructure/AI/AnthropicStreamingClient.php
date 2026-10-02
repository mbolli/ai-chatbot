<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\StreamEnd;
use App\Domain\Service\Stream\TextDelta;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;
use App\Infrastructure\AI\Tools\ToolInterface;
use Swoole\Coroutine\Socket;

/**
 * Anthropic API client with true real-time streaming using Swoole coroutines.
 *
 * Uses Swoole's raw coroutine Socket with recv() to read SSE events as they
 * arrive, yielding text chunks immediately without buffering the entire response.
 * The recv() call properly yields to the coroutine scheduler while waiting for data.
 *
 * Supports tool use (function calling) for document creation.
 */
final class AnthropicStreamingClient {
    private const string API_HOST = 'api.anthropic.com';
    private const string API_VERSION = '2023-06-01';
    private const int DEFAULT_MAX_TOKENS = 4096;
    private const array LOW_EFFORT_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    /** @var list<ToolInterface> */
    private array $tools = [];

    public function __construct(
        private readonly string $apiKey,
        private readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
    ) {}

    /**
     * @param list<ToolInterface> $tools
     */
    public function setTools(array $tools): void {
        $this->tools = $tools;
    }

    /**
     * Stream chat completion from Anthropic API with true real-time streaming.
     *
     * @param array<array{role: string, content: string}> $messages Conversation messages
     * @param string                                      $model    Model ID (e.g., claude-haiku-4-5)
     * @param null|string                                 $system   Optional system prompt
     *
     * @return \Generator<int, StreamEnd|TextDelta|ToolCall|ToolResult> Ends with exactly one StreamEnd
     *
     * @throws \RuntimeException On API errors with descriptive message
     */
    public function streamChatRealtime(array $messages, string $model, ?string $system = null): \Generator {
        // Build tools array if available
        $tools = $this->buildToolsArray();

        $payload = $this->basePayload($model, $messages);

        if ($system !== null) {
            $payload['system'] = $system;
        }

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        yield from $this->executeStreamingRequest($payload, $messages, $model, $system);
    }

    /**
     * Execute a streaming request to Anthropic API using raw socket for true streaming.
     *
     * @param array<string, mixed>                                     $payload
     * @param array<array{role: string, content: array<mixed>|string}> $originalMessages
     *
     * @return \Generator<int, StreamEnd|TextDelta|ToolCall|ToolResult>
     */
    private function executeStreamingRequest(array $payload, array $originalMessages, string $model, ?string $system): \Generator {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        // Create SSL socket connection
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);
        $socket->setProtocol([
            'open_ssl' => true,
            'ssl_host_name' => self::API_HOST,
            'ssl_verify_peer' => true,
        ]);

        if (!$socket->connect(self::API_HOST, 443, 30)) {
            throw new \RuntimeException('connection error: Failed to connect to Anthropic API - ' . $socket->errMsg);
        }

        // Build HTTP request
        $contentLength = \strlen($jsonPayload);
        $request = "POST /v1/messages HTTP/1.1\r\n";
        $request .= 'Host: ' . self::API_HOST . "\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Accept: text/event-stream\r\n";
        $request .= "x-api-key: {$this->apiKey}\r\n";
        $request .= 'anthropic-version: ' . self::API_VERSION . "\r\n";
        $request .= "Content-Length: {$contentLength}\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";
        $request .= $jsonPayload;

        if (!$socket->sendAll($request)) {
            $socket->close();

            throw new \RuntimeException('connection error: Failed to send request');
        }

        // Read HTTP response headers
        $headerBuffer = '';
        $headers = '';
        $remaining = '';
        while (true) {
            $data = $socket->recv(4096, 30);
            if ($data === false || $data === '') {
                $socket->close();

                throw new \RuntimeException('connection error: Connection closed while reading headers');
            }

            $headerBuffer .= $data;

            $headerEnd = strpos($headerBuffer, "\r\n\r\n");
            if ($headerEnd !== false) {
                $headers = substr($headerBuffer, 0, $headerEnd);
                $remaining = substr($headerBuffer, $headerEnd + 4);

                break;
            }
        }

        // Parse status code
        if (!preg_match('/HTTP\/[\d.]+ (\d+)/', $headers, $matches)) {
            $socket->close();

            throw new \RuntimeException('API error: Invalid HTTP response');
        }

        $statusCode = (int) $matches[1];

        $decoder = preg_match('/^transfer-encoding:\s*chunked/im', $headers) === 1 ? new ChunkedDecoder() : null;
        $remaining = $decoder?->decode($remaining) ?? $remaining;

        if ($statusCode >= 400) {
            // Read error body
            $errorBody = $remaining;
            while (true) {
                $data = $socket->recv(4096, 5);
                if ($data === false || $data === '') {
                    break;
                }

                $errorBody .= $decoder?->decode($data) ?? $data;
            }

            $socket->close();

            throw new \RuntimeException($this->parseErrorMessage($errorBody, $statusCode), $statusCode);
        }

        // Process SSE stream in real-time
        $buffer = $remaining;
        $hasYieldedContent = false;

        // All content blocks by index, echoed back unchanged when continuing after tool use
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = [];

        /** @var array<int, string> $toolInputJson */
        $toolInputJson = [];
        $stopReason = StopReason::Unknown;

        while (true) {
            // Process complete lines from buffer
            while (($lineEnd = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $lineEnd);
                $buffer = substr($buffer, $lineEnd + 1);
                $line = trim($line);

                if ($line === '' || !str_starts_with($line, 'data: ')) {
                    continue;
                }

                $jsonStr = substr($line, 6); // Remove 'data: ' prefix

                try {
                    $event = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }

                $type = $event['type'] ?? '';

                $index = (int) ($event['index'] ?? 0);

                if ($type === 'content_block_delta' && isset($blocks[$index])) {
                    $delta = $event['delta'] ?? [];

                    switch ($delta['type'] ?? '') {
                        case 'text_delta':
                            $hasYieldedContent = true;
                            $blocks[$index]['text'] .= $delta['text'];

                            yield new TextDelta($delta['text']);

                            break;

                        case 'thinking_delta':
                            $blocks[$index]['thinking'] .= $delta['thinking'];

                            break;

                        case 'signature_delta':
                            $blocks[$index]['signature'] = $delta['signature'];

                            break;

                        case 'input_json_delta':
                            $toolInputJson[$index] = ($toolInputJson[$index] ?? '') . $delta['partial_json'];

                            break;
                    }
                } elseif ($type === 'content_block_start') {
                    $blocks[$index] = $event['content_block'] ?? [];
                } elseif ($type === 'content_block_stop' && ($blocks[$index]['type'] ?? '') === 'tool_use') {
                    try {
                        $input = json_decode(($toolInputJson[$index] ?? '') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        $input = [];
                    }
                    // An empty PHP array would encode as [], the API expects an object
                    $blocks[$index]['input'] = \is_array($input) && $input !== [] ? $input : new \stdClass();
                } elseif ($type === 'message_delta') {
                    $stopReason = match ($event['delta']['stop_reason'] ?? null) {
                        'end_turn', 'tool_use' => StopReason::EndTurn,
                        'max_tokens' => StopReason::MaxTokens,
                        'refusal' => StopReason::Refusal,
                        'stop_sequence' => StopReason::StopSequence,
                        default => StopReason::Unknown,
                    };
                } elseif ($type === 'message_stop') {
                    $toolCalls = array_values(array_filter($blocks, static fn (array $b): bool => ($b['type'] ?? '') === 'tool_use'));

                    if ($toolCalls !== []) {
                        $socket->close();

                        $toolResults = yield from $this->executeToolCalls($toolCalls);

                        // Echo the assistant turn back unchanged (thinking blocks must keep their signatures)
                        ksort($blocks);
                        $assistantContent = array_values(array_filter(
                            $blocks,
                            static fn (array $b): bool => ($b['type'] ?? '') !== 'text' || ($b['text'] ?? '') !== '',
                        ));

                        $continuationMessages = $originalMessages;
                        $continuationMessages[] = [
                            'role' => 'assistant',
                            'content' => $assistantContent,
                        ];
                        $continuationMessages[] = [
                            'role' => 'user',
                            'content' => $this->buildToolResultContent($toolResults),
                        ];

                        // Continue streaming with tool results
                        $continuationPayload = $this->basePayload($model, $continuationMessages);

                        if ($system !== null) {
                            $continuationPayload['system'] = $system;
                        }

                        $tools = $this->buildToolsArray();
                        if (!empty($tools)) {
                            $continuationPayload['tools'] = $tools;
                        }

                        // Recursively stream the continuation (allows multiple tool calls)
                        yield from $this->executeStreamingRequest($continuationPayload, $continuationMessages, $model, $system);

                        return;
                    }

                    $socket->close();

                    yield new StreamEnd($stopReason);

                    return;
                }

                if ($type === 'error') {
                    $socket->close();

                    throw new \RuntimeException($event['error']['message'] ?? 'Unknown Anthropic error');
                }
            }

            // Read more data from socket (yields to scheduler while waiting)
            $data = $socket->recv(4096, 60);
            if ($data === false || $data === '') {
                break; // Connection closed or timeout
            }

            $buffer .= $decoder?->decode($data) ?? $data;
        }

        $socket->close();

        if (!$hasYieldedContent) {
            error_log('Anthropic API stream ended without content. Remaining buffer: ' . substr($buffer, 0, 200));
        }

        yield new StreamEnd($stopReason);
    }

    /**
     * @param array<array{role: string, content: array<mixed>|string}> $messages
     *
     * @return array<string, mixed>
     */
    private function basePayload(string $model, array $messages): array {
        $payload = [
            'model' => $model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

        // These models always think, and thinking tokens count against max_tokens
        if (\in_array($model, self::LOW_EFFORT_MODELS, true)) {
            $payload['output_config'] = ['effort' => 'low'];
        }

        return $payload;
    }

    /**
     * Parse error message from API response.
     */
    private function parseErrorMessage(string $body, int $statusCode): string {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $message = $data['error']['message'] ?? "HTTP {$statusCode}";
        } catch (\JsonException) {
            $message = "HTTP {$statusCode}";
        }

        return match ($statusCode) {
            401 => 'invalid_api_key: ' . $message,
            429 => 'rate limit exceeded: ' . $message,
            529 => 'overloaded: Anthropic API is overloaded',
            408 => 'timeout: Request timed out',
            default => $statusCode >= 500
                ? "server error (HTTP {$statusCode}): {$message}"
                : "API error (HTTP {$statusCode}): {$message}",
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
     * @param list<array<string, mixed>> $toolCalls tool_use content blocks
     *
     * @return \Generator<int, ToolCall|ToolResult, mixed, list<array{tool_use_id: string, content: string, is_error: bool}>>
     */
    private function executeToolCalls(array $toolCalls): \Generator {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $id = (string) $toolCall['id'];
            $name = (string) $toolCall['name'];
            $input = (array) $toolCall['input'];

            yield new ToolCall($id, $name, $input);

            $tool = array_find($this->tools, static fn (ToolInterface $t): bool => $t->name() === $name);

            try {
                $content = $tool?->execute($input) ?? "Error: Unknown tool '{$name}'";
            } catch (\Throwable $e) {
                $content = 'Error: ' . $e->getMessage();
            }
            $isError = str_starts_with($content, 'Error:');

            yield new ToolResult($id, $name, $content, $isError);

            $results[] = ['tool_use_id' => $id, 'content' => $content, 'is_error' => $isError];
        }

        return $results;
    }

    /**
     * Build user message content with tool results.
     *
     * @param list<array{tool_use_id: string, content: string, is_error: bool}> $toolResults
     *
     * @return list<array{type: string, tool_use_id: string, content: string, is_error: bool}>
     */
    private function buildToolResultContent(array $toolResults): array {
        $content = [];

        foreach ($toolResults as $result) {
            $content[] = [
                'type' => 'tool_result',
                'tool_use_id' => $result['tool_use_id'],
                'content' => $result['content'],
                'is_error' => $result['is_error'],
            ];
        }

        return $content;
    }

    /**
     * Format messages array for Anthropic API.
     *
     * @param array<array{role: string, content: array<mixed>|string}> $messages
     *
     * @return array<array{role: string, content: array<mixed>|string}>
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
