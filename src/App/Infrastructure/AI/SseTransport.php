<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

interface SseTransport {
    /**
     * POST a JSON body and stream the Server-Sent Events of the response.
     *
     * @param array<string, string> $headers
     *
     * @return \Generator<int, SseEvent>
     *
     * @throws TransportException On connection failure or an HTTP error status, before the first event
     */
    public function stream(string $path, array $headers, string $body): \Generator;
}
