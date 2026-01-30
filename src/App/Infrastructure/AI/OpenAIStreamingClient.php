<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Infrastructure\AI\Tools\CreateDocumentTool;
use App\Infrastructure\AI\Tools\UpdateDocumentTool;
use OpenSwoole\Coroutine\Socket;

/**
 * OpenAI API client with true real-time streaming using OpenSwoole coroutines.
 *
 * Uses OpenSwoole's raw coroutine Socket with recv() to read SSE events as they
 * arrive, yielding text chunks immediately without buffering the entire response.
 * The recv() call properly yields to the coroutine scheduler while waiting for data.
 *
 * Supports tool use (function calling) for document creation.
 */
final class OpenAIStreamingClient {
    private const string API_HOST = 'api.openai.com';
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
     * Stream chat completion from OpenAI API with true real-time streaming.
     *
     * @param array<array{role: string, content: string}> $messages Conversation messages
     * @param string                                      $model    Model ID (e.g., gpt-5-nano)
     * @param null|string                                 $system   Optional system prompt
     *
     * @return \Generator<string> Yields text chunks as they arrive
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

        yield from $this->executeStreamingRequest($payload, $messages, $model, $system);
    }

    /**
     * Execute a streaming request to OpenAI API using raw socket for true streaming.
     *
     * @param array<string, mixed>                        $payload
     * @param array<array{role: string, content: string}> $originalMessages
     *
     * @return \Generator<string>
     */
    private function executeStreamingRequest(array $payload, array $originalMessages, string $model, ?string $system): \Generator {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        // Create SSL socket connection
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);
        $socket->setProtocol(['open_ssl' => true]);

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
        if ($statusCode >= 400) {
            // Read error body
            $errorBody = $remaining;
            while (($chunk = $socket->recv(4096, 5)) !== false && $chunk !== '') {
                $errorBody .= $chunk;
            }
            $socket->close();

            throw new \RuntimeException("HTTP error from AI engine ({$statusCode}): {$errorBody}");
        }

        // Track tool calls being accumulated
        $toolCalls = [];
        $currentToolIndex = -1;

        // Stream SSE events
        $buffer = $remaining;
        while (true) {
            $chunk = $socket->recv(4096, 30);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            // Process complete lines from buffer
            while (($lineEnd = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $lineEnd);
                $buffer = substr($buffer, $lineEnd + 1);

                // Remove any chunk size lines if chunked encoding
                $line = trim($line);
                if ($line === '' || ctype_xdigit($line)) {
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
                                yield $delta['content'];
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
                                        $currentToolIndex = $index;
                                    } elseif ($currentToolIndex >= 0 && isset($toolCalls[$currentToolIndex])) {
                                        // Append to existing tool call arguments
                                        if (isset($toolCallDelta['function']['arguments'])) {
                                            $toolCalls[$currentToolIndex]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
                                        }
                                    }
                                }
                            }
                        }

                        // Check for finish reason
                        $finishReason = $event['choices'][0]['finish_reason'] ?? null;
                        if ($finishReason === 'tool_calls' && !empty($toolCalls)) {
                            // Process tool calls
                            yield from $this->processToolCalls($toolCalls, $originalMessages, $model, $system);
                        }
                    } catch (\JsonException) {
                        // Skip malformed JSON
                    }
                }
            }
        }

        $socket->close();
    }

    /**
     * Process tool calls and continue the conversation.
     *
     * @param array<int, array{id: string, function: array{name: string, arguments: string}}> $toolCalls
     * @param array<array{role: string, content: string}>                                     $originalMessages
     *
     * @return \Generator<string>
     */
    private function processToolCalls(array $toolCalls, array $originalMessages, string $model, ?string $system): \Generator {
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'];
            $arguments = json_decode($toolCall['function']['arguments'], true) ?? [];

            $result = $this->executeTool($functionName, $arguments);

            $toolResults[] = [
                'tool_call_id' => $toolCall['id'],
                'role' => 'tool',
                'content' => json_encode($result, JSON_THROW_ON_ERROR),
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
            'max_tokens' => $this->maxTokens,
            'messages' => $formattedMessages,
            'stream' => true,
        ];

        $tools = $this->buildToolsArray();
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        yield from $this->executeStreamingRequest($payload, $continuationMessages, $model, $system);
    }

    /**
     * Execute a tool and return the result.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function executeTool(string $functionName, array $arguments): array {
        return match ($functionName) {
            'createDocument' => $this->executeCreateDocument($arguments),
            'updateDocument' => $this->executeUpdateDocument($arguments),
            default => ['error' => "Unknown function: {$functionName}"],
        };
    }

    /**
     * Execute createDocument tool.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function executeCreateDocument(array $arguments): array {
        if ($this->createDocumentTool === null) {
            return ['error' => 'Document creation not available'];
        }

        try {
            $title = $arguments['title'] ?? 'Untitled';
            $kind = $arguments['kind'] ?? 'text';
            $content = $arguments['content'] ?? '';
            $language = $arguments['language'] ?? null;

            $result = $this->createDocumentTool->createDocument($kind, $title, $content, $language);

            // Get the document from the tool to access the ID
            $document = $this->createDocumentTool->getLastCreatedDocument();
            $documentId = $document !== null ? $document->id : 'unknown';

            return [
                'success' => !str_contains($result, 'Error:'),
                'document_id' => $documentId,
                'message' => $result,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Execute updateDocument tool.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function executeUpdateDocument(array $arguments): array {
        if ($this->updateDocumentTool === null) {
            return ['error' => 'Document update not available'];
        }

        try {
            $documentId = $arguments['document_id'] ?? '';
            $content = $arguments['content'] ?? '';

            $this->updateDocumentTool->updateDocument($documentId, $content);

            return [
                'success' => true,
                'message' => "Updated document {$documentId}",
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Build tools array for OpenAI API.
     *
     * @return array<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    private function buildToolsArray(): array {
        $tools = [];

        if ($this->createDocumentTool !== null) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'createDocument',
                    'description' => 'Create a new document (text, code, spreadsheet, or image). Use this when the user asks you to create, write, or generate content that should be saved as a document.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => [
                                'type' => 'string',
                                'description' => 'Title for the document',
                            ],
                            'kind' => [
                                'type' => 'string',
                                'enum' => ['text', 'code', 'sheet', 'image'],
                                'description' => 'Type of document to create',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Initial content for the document. For code, include the full source code. For sheets, use CSV format. For images, provide an SVG string or image generation prompt.',
                            ],
                        ],
                        'required' => ['title', 'kind', 'content'],
                    ],
                ],
            ];
        }

        if ($this->updateDocumentTool !== null) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'updateDocument',
                    'description' => 'Update an existing document with new content.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'document_id' => [
                                'type' => 'string',
                                'description' => 'ID of the document to update',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'New content for the document',
                            ],
                        ],
                        'required' => ['document_id', 'content'],
                    ],
                ],
            ];
        }

        return $tools;
    }
}
