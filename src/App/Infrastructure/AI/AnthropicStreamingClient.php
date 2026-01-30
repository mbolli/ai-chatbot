<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Infrastructure\AI\Tools\CreateDocumentTool;
use App\Infrastructure\AI\Tools\UpdateDocumentTool;
use OpenSwoole\Coroutine\Socket;

/**
 * Anthropic API client with true real-time streaming using OpenSwoole coroutines.
 *
 * Uses OpenSwoole's raw coroutine Socket with recv() to read SSE events as they
 * arrive, yielding text chunks immediately without buffering the entire response.
 * The recv() call properly yields to the coroutine scheduler while waiting for data.
 *
 * Supports tool use (function calling) for document creation.
 */
final class AnthropicStreamingClient {
    private const string API_HOST = 'api.anthropic.com';
    private const string API_VERSION = '2023-06-01';
    private const int DEFAULT_MAX_TOKENS = 4096;

    private ?CreateDocumentTool $createDocumentTool = null;
    private ?UpdateDocumentTool $updateDocumentTool = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
    ) {}

    /**
     * Set tools for this client.
     */
    public function setTools(?CreateDocumentTool $createTool, ?UpdateDocumentTool $updateTool): void {
        $this->createDocumentTool = $createTool;
        $this->updateDocumentTool = $updateTool;
    }

    /**
     * Stream chat completion from Anthropic API with true real-time streaming.
     *
     * @param array<array{role: string, content: string}> $messages Conversation messages
     * @param string                                      $model    Model ID (e.g., claude-sonnet-4-5-20250929)
     * @param null|string                                 $system   Optional system prompt
     *
     * @return \Generator<string> Yields text chunks as they arrive
     *
     * @throws \RuntimeException On API errors with descriptive message
     */
    public function streamChatRealtime(array $messages, string $model, ?string $system = null): \Generator {
        // Build tools array if available
        $tools = $this->buildToolsArray();

        $payload = [
            'model' => $model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

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
     * @return \Generator<string>
     */
    private function executeStreamingRequest(array $payload, array $originalMessages, string $model, ?string $system): \Generator {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        // Create SSL socket connection
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);
        $socket->setProtocol(['open_ssl' => true]);

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

        if ($statusCode >= 400) {
            // Read error body
            $errorBody = $remaining;
            while (true) {
                $data = $socket->recv(4096, 5);
                if ($data === false || $data === '') {
                    break;
                }

                $errorBody .= $data;
            }

            $socket->close();

            throw new \RuntimeException($this->parseErrorMessage($errorBody, $statusCode), $statusCode);
        }

        // Process SSE stream in real-time
        $buffer = $remaining;
        $hasYieldedContent = false;

        // Track tool use state
        $toolUseState = [
            'activeToolUse' => null,
            'pendingToolCalls' => [],
        ];

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

                if ($jsonStr === '[DONE]') {
                    $socket->close();

                    if (!$hasYieldedContent) {
                        error_log('Anthropic API returned no content for model: ' . $model);
                    }

                    return;
                }

                try {
                    $event = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }

                $type = $event['type'] ?? '';

                // Handle text content
                if ($type === 'content_block_delta') {
                    $delta = $event['delta'] ?? [];
                    $deltaType = $delta['type'] ?? '';

                    if ($deltaType === 'text_delta' && isset($delta['text'])) {
                        $hasYieldedContent = true;

                        yield $delta['text'];
                    } elseif ($deltaType === 'input_json_delta' && isset($delta['partial_json'])) {
                        // Accumulate tool input JSON
                        if ($toolUseState['activeToolUse'] !== null) {
                            $toolUseState['activeToolUse']['input_json'] .= $delta['partial_json'];
                        }
                    }
                } elseif ($type === 'content_block_start') {
                    $contentBlock = $event['content_block'] ?? [];
                    if (($contentBlock['type'] ?? '') === 'tool_use') {
                        // Start a new tool use block
                        $toolUseState['activeToolUse'] = [
                            'id' => $contentBlock['id'] ?? '',
                            'name' => $contentBlock['name'] ?? '',
                            'input_json' => '',
                        ];
                    }
                } elseif ($type === 'content_block_stop') {
                    // Finalize tool use block if active
                    if ($toolUseState['activeToolUse'] !== null) {
                        $toolCall = $toolUseState['activeToolUse'];

                        try {
                            $toolCall['input'] = json_decode($toolCall['input_json'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                        } catch (\JsonException) {
                            $toolCall['input'] = [];
                        }
                        unset($toolCall['input_json']);
                        $toolUseState['pendingToolCalls'][] = $toolCall;
                        $toolUseState['activeToolUse'] = null;
                    }
                } elseif ($type === 'message_stop') {
                    // Check if we have pending tool calls
                    if (!empty($toolUseState['pendingToolCalls'])) {
                        $socket->close();

                        // Execute tool calls and continue the conversation
                        $toolResults = $this->executeToolCalls($toolUseState['pendingToolCalls']);

                        // Build continuation messages with tool results
                        $continuationMessages = $originalMessages;
                        $continuationMessages[] = [
                            'role' => 'assistant',
                            'content' => $this->buildAssistantToolUseContent($toolUseState['pendingToolCalls']),
                        ];
                        $continuationMessages[] = [
                            'role' => 'user',
                            'content' => $this->buildToolResultContent($toolResults),
                        ];

                        // Continue streaming with tool results
                        $continuationPayload = [
                            'model' => $model,
                            'max_tokens' => $this->maxTokens,
                            'messages' => $this->formatMessages($continuationMessages),
                            'stream' => true,
                        ];

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

            $buffer .= $data;
        }

        $socket->close();

        if (!$hasYieldedContent) {
            error_log('Anthropic API stream ended without content. Remaining buffer: ' . substr($buffer, 0, 200));
        }
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
     * Build the tools array for the API request.
     *
     * @return array<array{name: string, description: string, input_schema: array}>
     */
    private function buildToolsArray(): array {
        $tools = [];

        if ($this->createDocumentTool !== null) {
            $tools[] = [
                'name' => 'createDocument',
                'description' => 'Create a new document artifact (code, text, spreadsheet, or image). Use this when the user asks you to write, create, or generate content that would benefit from being in a separate editable document.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'kind' => [
                            'type' => 'string',
                            'enum' => ['text', 'code', 'sheet', 'image'],
                            'description' => 'The type of document: "text" for markdown/prose, "code" for programming code, "sheet" for CSV data, "image" for SVG content',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'A short descriptive title for the document',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'The actual content of the document',
                        ],
                        'language' => [
                            'type' => 'string',
                            'description' => 'For code documents, the programming language (e.g., "python", "javascript", "php")',
                        ],
                    ],
                    'required' => ['kind', 'title', 'content'],
                ],
            ];
        }

        if ($this->updateDocumentTool !== null) {
            $tools[] = [
                'name' => 'updateDocument',
                'description' => 'Update an existing document artifact with new content.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'documentId' => [
                            'type' => 'string',
                            'description' => 'The ID of the document to update',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'The new content for the document',
                        ],
                    ],
                    'required' => ['documentId', 'content'],
                ],
            ];
        }

        return $tools;
    }

    /**
     * Execute tool calls and return results.
     *
     * @param array<array{id: string, name: string, input: array}> $toolCalls
     *
     * @return array<array{tool_use_id: string, content: string}>
     */
    private function executeToolCalls(array $toolCalls): array {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $result = match ($toolCall['name']) {
                'createDocument' => $this->createDocumentTool?->createDocument(
                    $toolCall['input']['kind'] ?? 'text',
                    $toolCall['input']['title'] ?? 'Untitled',
                    $toolCall['input']['content'] ?? '',
                    $toolCall['input']['language'] ?? null,
                ) ?? 'Error: Tool not available',
                'updateDocument' => $this->updateDocumentTool?->updateDocument(
                    $toolCall['input']['documentId'] ?? '',
                    $toolCall['input']['content'] ?? '',
                ) ?? 'Error: Tool not available',
                default => "Error: Unknown tool '{$toolCall['name']}'",
            };

            $results[] = [
                'tool_use_id' => $toolCall['id'],
                'content' => $result,
            ];
        }

        return $results;
    }

    /**
     * Build assistant message content with tool use blocks.
     *
     * @param array<array{id: string, name: string, input: array}> $toolCalls
     *
     * @return array<array{type: string, id?: string, name?: string, input?: array}>
     */
    private function buildAssistantToolUseContent(array $toolCalls): array {
        $content = [];

        foreach ($toolCalls as $toolCall) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $toolCall['id'],
                'name' => $toolCall['name'],
                'input' => $toolCall['input'],
            ];
        }

        return $content;
    }

    /**
     * Build user message content with tool results.
     *
     * @param array<array{tool_use_id: string, content: string}> $toolResults
     *
     * @return array<array{type: string, tool_use_id: string, content: string}>
     */
    private function buildToolResultContent(array $toolResults): array {
        $content = [];

        foreach ($toolResults as $result) {
            $content[] = [
                'type' => 'tool_result',
                'tool_use_id' => $result['tool_use_id'],
                'content' => $result['content'],
            ];
        }

        return $content;
    }

    /**
     * Format messages array for Anthropic API.
     *
     * @param array<array{role: string, content: array|string}> $messages
     *
     * @return array<array{role: string, content: array|string}>
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
