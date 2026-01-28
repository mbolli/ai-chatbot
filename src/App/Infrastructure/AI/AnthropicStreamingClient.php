<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Swoole\Coroutine\Socket;

/**
 * Anthropic API client with true real-time streaming using Swoole coroutines.
 *
 * Why not use the official SDK?
 *
 * The anthropic-ai/sdk package uses PSR-18 HTTP clients which buffer
 * the entire response before returning. For true real-time streaming
 * in a Swoole environment, we need direct socket control to yield
 * chunks as they arrive from the API.
 *
 * This client uses Swoole's raw coroutine Socket to read and yield
 * SSE chunks immediately as they arrive without buffering.
 */
final class AnthropicStreamingClient {
    private const string API_HOST = 'api.anthropic.com';
    private const string API_VERSION = '2023-06-01';
    private const int DEFAULT_MAX_TOKENS = 2048;

    public function __construct(
        private readonly string $apiKey,
        private readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
    ) {}

    /**
     * Stream chat completion from Anthropic API with true real-time streaming.
     *
     * Uses raw Swoole socket to read SSE events as they arrive, yielding
     * text chunks immediately without buffering the entire response.
     *
     * @param array<array{role: string, content: string}> $messages Conversation messages
     * @param string                                      $model    Model ID (e.g., claude-3-haiku-20240307)
     * @param null|string                                 $system   Optional system prompt
     *
     * @return \Generator<string> Yields text chunks as they arrive
     *
     * @throws \RuntimeException On API errors with descriptive message
     */
    public function streamChatRealtime(array $messages, string $model, ?string $system = null): \Generator {
        $payload = [
            'model' => $model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

        if ($system !== null) {
            $payload['system'] = $system;
        }

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
        $request .= "Host: " . self::API_HOST . "\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Accept: text/event-stream\r\n";
        $request .= "x-api-key: {$this->apiKey}\r\n";
        $request .= "anthropic-version: " . self::API_VERSION . "\r\n";
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

                if ($type === 'content_block_delta') {
                    $delta = $event['delta'] ?? [];
                    if (($delta['type'] ?? '') === 'text_delta' && isset($delta['text'])) {
                        $hasYieldedContent = true;

                        yield $delta['text'];
                    }
                }

                if ($type === 'message_stop') {
                    $socket->close();

                    return;
                }

                if ($type === 'error') {
                    $socket->close();

                    throw new \RuntimeException($event['error']['message'] ?? 'Unknown Anthropic error');
                }
            }

            // Read more data from socket
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
     * Format messages array for Anthropic API.
     *
     * @param array<array{role: string, content: string}> $messages
     *
     * @return array<array{role: string, content: string}>
     */
    private function formatMessages(array $messages): array {
        $formatted = [];

        foreach ($messages as $msg) {
            // Anthropic only accepts 'user' and 'assistant' roles in messages
            // System messages should be passed via the 'system' parameter
            $role = $msg['role'];

            if ($role === 'system') {
                // System messages are handled separately
                continue;
            }

            // Ensure role is valid
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
