<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Infrastructure\AI\Tools\CreateDocumentTool;
use App\Infrastructure\AI\Tools\UpdateDocumentTool;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

/**
 * Anthropic API client with true real-time streaming using cURL + OpenSwoole.
 *
 * Uses cURL with CURLOPT_WRITEFUNCTION for streaming, which works with
 * OpenSwoole's coroutine hooks. Chunks are passed through a Channel
 * to enable real-time yielding as they arrive from the API.
 *
 * Supports tool use (function calling) for document creation.
 */
final class AnthropicStreamingClient {
    private const string API_HOST = 'https://api.anthropic.com';
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
     * Execute a streaming request to Anthropic API.
     *
     * @return \Generator<string>
     */
    private function executeStreamingRequest(array $payload, array $originalMessages, string $model, ?string $system): \Generator {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        // Channel for passing chunks from cURL callback to generator
        $channel = new Channel(100);

        // Track tool use state
        $toolUseState = [
            'activeToolUse' => null,  // Current tool use block being built
            'pendingToolCalls' => [], // Completed tool calls to execute
        ];

        // Run cURL in separate coroutine so we can yield from this one
        Coroutine::create(function () use ($jsonPayload, $channel, &$toolUseState): void {
            $buffer = '';

            $ch = curl_init(self::API_HOST . '/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                ],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($channel, &$buffer, &$toolUseState): int {
                    $buffer .= $data;

                    // Process complete lines
                    while (($lineEnd = mb_strpos($buffer, "\n")) !== false) {
                        $line = mb_substr($buffer, 0, $lineEnd);
                        $buffer = mb_substr($buffer, $lineEnd + 1);
                        $line = mb_trim($line);

                        if ($line === '' || !str_starts_with($line, 'data: ')) {
                            continue;
                        }

                        $jsonStr = mb_substr($line, 6);

                        if ($jsonStr === '[DONE]') {
                            continue;
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
                                $channel->push(['type' => 'chunk', 'data' => $delta['text']]);
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
                                $channel->push(['type' => 'tool_calls', 'data' => $toolUseState['pendingToolCalls']]);
                                $toolUseState['pendingToolCalls'] = [];
                            }
                        } elseif ($type === 'error') {
                            $channel->push(['type' => 'error', 'data' => $event['error']['message'] ?? 'Unknown error']);
                        }
                    }

                    return mb_strlen($data);
                },
            ]);

            $result = curl_exec($ch);

            if ($result === false) {
                $error = curl_error($ch);
                $errno = curl_errno($ch);
                curl_close($ch);
                $channel->push(['type' => 'error', 'data' => "cURL error ({$errno}): {$error}"]);
                $channel->push(['type' => 'done']);

                return;
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400) {
                $channel->push(['type' => 'error', 'data' => "HTTP error: {$httpCode}"]);
            }

            $channel->push(['type' => 'done']);
        });

        // Yield chunks as they arrive
        $hasYieldedContent = false;

        while (true) {
            $item = $channel->pop(60.0); // 60 second timeout

            if ($item === false) {
                break;
            }

            if ($item['type'] === 'done') {
                break;
            }

            if ($item['type'] === 'error') {
                throw new \RuntimeException($item['data']);
            }

            if ($item['type'] === 'chunk') {
                $hasYieldedContent = true;

                yield $item['data'];
            }

            if ($item['type'] === 'tool_calls') {
                // Execute tool calls and continue the conversation
                $toolResults = $this->executeToolCalls($item['data']);

                // Build continuation messages with tool results
                $continuationMessages = $originalMessages;
                $continuationMessages[] = [
                    'role' => 'assistant',
                    'content' => $this->buildAssistantToolUseContent($item['data']),
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

                break; // Exit this loop since we're continuing in recursion
            }
        }

        $channel->close();

        if (!$hasYieldedContent) {
            error_log('Anthropic API stream ended without content. Model: ' . $model);
        }
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
