<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use OpenSwoole\Coroutine\Socket;

/**
 * HTTP/1.1 over a TLS-verified Swoole coroutine socket. recv() yields to the scheduler,
 * so events reach the caller as soon as they arrive.
 */
final readonly class SseHttpTransport implements SseTransport {
    private const int CONNECT_TIMEOUT = 30;
    private const int READ_TIMEOUT = 60;
    private const int ERROR_BODY_TIMEOUT = 5;

    public function __construct(
        private string $host,
        private int $port = 443,
    ) {}

    public function stream(string $path, array $headers, string $body): \Generator {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);
        $socket->setProtocol([
            'open_ssl' => true,
            'ssl_host_name' => $this->host,
            'ssl_verify_peer' => true,
        ]);

        try {
            if (!$socket->connect($this->host, $this->port, self::CONNECT_TIMEOUT)) {
                throw new TransportException("Failed to connect to {$this->host}: {$socket->errMsg}");
            }

            if (!$socket->sendAll($this->buildRequest($path, $headers, $body))) {
                throw new TransportException("Failed to send request to {$this->host}");
            }

            $head = '';
            while (($headEnd = strpos($head, "\r\n\r\n")) === false) {
                $data = $socket->recv(8192, self::READ_TIMEOUT);
                if ($data === false || $data === '') {
                    throw new TransportException("Connection to {$this->host} closed while reading headers");
                }

                $head .= $data;
            }

            [$status, $responseHeaders] = self::parseHead(substr($head, 0, $headEnd));
            $data = substr($head, $headEnd + 4);
            $decoder = str_contains(strtolower($responseHeaders['transfer-encoding'] ?? ''), 'chunked') ? new ChunkedDecoder() : null;

            if ($status >= 400) {
                $errorBody = '';
                do {
                    $errorBody .= $decoder?->decode($data) ?? $data;
                } while (!($decoder?->isDone() ?? false) && ($data = $socket->recv(8192, self::ERROR_BODY_TIMEOUT)) !== false && $data !== '');

                throw new TransportException(
                    "HTTP {$status}",
                    $status,
                    $errorBody,
                    RetryPolicy::parseRetryAfter($responseHeaders['retry-after'] ?? null),
                );
            }

            $parser = new SseParser();
            while (true) {
                foreach ($parser->feed($decoder?->decode($data) ?? $data) as $event) {
                    yield $event;
                }

                if ($decoder?->isDone() ?? false) {
                    break;
                }

                $data = $socket->recv(8192, self::READ_TIMEOUT);
                if ($data === false || $data === '') {
                    break;
                }
            }

            $last = $parser->flush();
            if ($last !== null) {
                yield $last;
            }
        } finally {
            $socket->close();
        }
    }

    /**
     * @return array{int, array<string, string>} Status code and headers with lowercase names
     *
     * @throws TransportException
     */
    public static function parseHead(string $head): array {
        $lines = explode("\r\n", $head);

        if (preg_match('#^HTTP/[\d.]+ (\d{3})#', $lines[0], $matches) !== 1) {
            throw new TransportException('Invalid HTTP response: ' . substr($lines[0], 0, 100));
        }

        $headers = [];
        foreach (\array_slice($lines, 1) as $line) {
            $parts = explode(':', $line, 2);
            if (\count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [(int) $matches[1], $headers];
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildRequest(string $path, array $headers, string $body): string {
        $request = "POST {$path} HTTP/1.1\r\nHost: {$this->host}\r\n";

        $headers += [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
            'Content-Length' => (string) \strlen($body),
            'Connection' => 'close',
        ];
        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }

        return $request . "\r\n" . $body;
    }
}
