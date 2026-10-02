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
 * OpenAI API client with true real-time streaming using Swoole coroutines.
 *
 * Uses Swoole's raw coroutine Socket with recv() to read SSE events as they
 * arrive, yielding text chunks immediately without buffering the entire response.
 * The recv() call properly yields to the coroutine scheduler while waiting for data.
 *
 * Supports tool use (function calling) for document creation.
 */
final class OpenAIStreamingClient {
    private const string API_HOST = 'api.openai.com';
    private const int DEFAULT_MAX_TOKENS = 4096;

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
    ) {}

    /**
     * @param list<ToolInterface> $tools
     */
    public function setTools(array $tools): void {
        $this->tools = $tools;
    }

    /**
     * Stream chat completion from OpenAI API with true real-time streaming.
     *
     * @param array<array{role: string, content: string}> $messages Conversation messages
     * @param string                                      $model    Model ID (e.g., gpt-5-nano)
     * @param null|string                                 $system   Optional system prompt
     *
     * @return \Generator<int, StreamEnd|TextDelta|ToolCall|ToolResult> Ends with exactly one StreamEnd
     *
     * @throws \RuntimeException On API errors with descriptive message
     */
    public function streamChatRealtime(array $messages, string $model, ?string $system = null): \Generator {
        // Build tools array if available
        $tools = $this->buildToolsArray();

        // Format messages for OpenAI (system message is part of messages array)
        $formattedMessages = [];
        if ($system !== null) {
            $formattedMessages[] = [
                'role' => 'system',
                'content' => $system,
            ];
        }

        foreach ($messages as $message) {
            $formattedMessages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        $payload = [
            'model' => $model,
            'max_completion_tokens' => $this->maxTokens,
            'messages' => $formattedMessages,
            'stream' => true,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        if (\in_array($model, self::NO_REASONING_MODELS, true)) {
            $payload['reasoning_effort'] = 'none';
        }

        yield from $this->executeStreamingRequest($payload, $messages, $model, $system);
    }

    /**
     * Execute a streaming request to OpenAI API using raw socket for true streaming.
     *
     * @param array<string, mixed>       $payload
     * @param list<array<string, mixed>> $originalMessages
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
            throw new \RuntimeException('Failed to connect to OpenAI API: ' . $socket->errMsg);
        }

        // Build HTTP request
        $contentLength = \strlen($jsonPayload);
        $request = "POST /v1/chat/completions HTTP/1.1\r\n";
        $request .= 'Host: ' . self::API_HOST . "\r\n";
        $request .= "Authorization: Bearer {$this->apiKey}\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: {$contentLength}\r\n";
        $request .= "Accept: text/event-stream\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";
        $request .= $jsonPayload;

        if (!$socket->sendAll($request)) {
            $socket->close();

            throw new \RuntimeException('Failed to send request to OpenAI API');
        }

        // Read and parse HTTP response headers
        $headers = '';
        $remaining = '';
        while (true) {
            $data = $socket->recv(4096, 30);
            if ($data === false || $data === '') {
                break;
            }
            $headers .= $data;
            if (($pos = strpos($headers, "\r\n\r\n")) !== false) {
                $remaining = substr($headers, $pos + 4);
                $headers = substr($headers, 0, $pos);

                break;
            }
        }

        // Parse status code
        if (!preg_match('/HTTP\/\d\.\d (\d{3})/', $headers, $matches)) {
            $socket->close();

            throw new \RuntimeException('Invalid HTTP response from OpenAI API');
        }

        $statusCode = (int) $matches[1];

        $decoder = preg_match('/^transfer-encoding:\s*chunked/im', $headers) === 1 ? new ChunkedDecoder() : null;
        $remaining = $decoder?->decode($remaining) ?? $remaining;
        if ($statusCode >= 400) {
            // Read error body
            $errorBody = $remaining;
            while (($chunk = $socket->recv(4096, 5)) !== false && $chunk !== '') {
                $errorBody .= $decoder?->decode($chunk) ?? $chunk;
            }
            $socket->close();

            throw new \RuntimeException("HTTP error from AI engine ({$statusCode}): {$errorBody}");
        }

        // Track tool calls being accumulated
        $toolCalls = [];
        $stopReason = StopReason::Unknown;

        // Stream SSE events
        $buffer = $remaining;
        while (true) {
            $chunk = $socket->recv(4096, 30);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $decoder?->decode($chunk) ?? $chunk;

            // Process complete lines from buffer
            while (($lineEnd = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $lineEnd);
                $buffer = substr($buffer, $lineEnd + 1);

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                // Parse SSE data lines
                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);

                    // End of stream
                    if ($data === '[DONE]') {
                        break 2;
                    }

                    try {
                        /** @var array{choices?: array<array{delta?: array{content?: string, tool_calls?: array<array{index?: int, id?: string, function?: array{name?: string, arguments?: string}}>}, finish_reason?: string}>} $event */
                        $event = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

                        if (isset($event['choices'][0]['delta'])) {
                            $delta = $event['choices'][0]['delta'];

                            // Handle content chunks
                            if (isset($delta['content']) && $delta['content'] !== '') {
                                yield new TextDelta($delta['content']);
                            }

                            // Handle tool calls
                            if (isset($delta['tool_calls'])) {
                                foreach ($delta['tool_calls'] as $toolCallDelta) {
                                    $index = $toolCallDelta['index'] ?? 0;

                                    // Initialize new tool call
                                    if (isset($toolCallDelta['id'])) {
                                        $toolCalls[$index] = [
                                            'id' => $toolCallDelta['id'],
                                            'function' => [
                                                'name' => $toolCallDelta['function']['name'] ?? '',
                                                'arguments' => $toolCallDelta['function']['arguments'] ?? '',
                                            ],
                                        ];
                                    } elseif (isset($toolCalls[$index], $toolCallDelta['function']['arguments'])) {
                                        $toolCalls[$index]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
                                    }
                                }
                            }
                        }

                        // Check for finish reason
                        $finishReason = $event['choices'][0]['finish_reason'] ?? null;
                        if ($finishReason === 'tool_calls' && !empty($toolCalls)) {
                            $socket->close();

                            // The continuation ends with its own StreamEnd
                            yield from $this->processToolCalls($toolCalls, $originalMessages, $model, $system);

                            return;
                        }

                        if ($finishReason !== null) {
                            $stopReason = match ($finishReason) {
                                'stop' => StopReason::EndTurn,
                                'length' => StopReason::MaxTokens,
                                'content_filter' => StopReason::Refusal,
                                default => StopReason::Unknown,
                            };
                        }
                    } catch (\JsonException) {
                        // Skip malformed JSON
                    }
                }
            }
        }

        $socket->close();

        yield new StreamEnd($stopReason);
    }

    /**
     * Process tool calls and continue the conversation.
     *
     * @param array<int, array{id: string, function: array{name: string, arguments: string}}> $toolCalls
     * @param list<array<string, mixed>>                                                      $originalMessages
     *
     * @return \Generator<int, StreamEnd|TextDelta|ToolCall|ToolResult>
     */
    private function processToolCalls(array $toolCalls, array $originalMessages, string $model, ?string $system): \Generator {
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $name = $toolCall['function']['name'];
            $decoded = json_decode($toolCall['function']['arguments'], true);
            $input = \is_array($decoded) ? $decoded : [];

            yield new ToolCall($toolCall['id'], $name, $input);

            $tool = array_find($this->tools, static fn (ToolInterface $t): bool => $t->name() === $name);

            try {
                $content = $tool?->execute($input) ?? "Error: Unknown tool '{$name}'";
            } catch (\Throwable $e) {
                $content = 'Error: ' . $e->getMessage();
            }

            yield new ToolResult($toolCall['id'], $name, $content, str_starts_with($content, 'Error:'));

            $toolResults[] = [
                'tool_call_id' => $toolCall['id'],
                'role' => 'tool',
                'content' => $content,
            ];
        }

        // Build messages with tool results for continuation
        $continuationMessages = $originalMessages;

        // Add assistant message with tool calls
        $assistantToolCalls = [];
        foreach ($toolCalls as $toolCall) {
            $assistantToolCalls[] = [
                'id' => $toolCall['id'],
                'type' => 'function',
                'function' => [
                    'name' => $toolCall['function']['name'],
                    'arguments' => $toolCall['function']['arguments'],
                ],
            ];
        }
        $continuationMessages[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $assistantToolCalls,
        ];

        // Add tool results
        foreach ($toolResults as $result) {
            $continuationMessages[] = $result;
        }

        // Format messages for OpenAI
        $formattedMessages = [];
        if ($system !== null) {
            $formattedMessages[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($continuationMessages as $msg) {
            $formattedMessages[] = $msg;
        }

        // Continue streaming with tool results
        $payload = [
            'model' => $model,
            'max_completion_tokens' => $this->maxTokens,
            'messages' => $formattedMessages,
            'stream' => true,
        ];

        $tools = $this->buildToolsArray();
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        if (\in_array($model, self::NO_REASONING_MODELS, true)) {
            $payload['reasoning_effort'] = 'none';
        }

        yield from $this->executeStreamingRequest($payload, $continuationMessages, $model, $system);
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
