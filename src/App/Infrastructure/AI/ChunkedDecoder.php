<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

/**
 * Incremental decoder for HTTP/1.1 chunked transfer encoding.
 *
 * Chunk boundaries can fall in the middle of an SSE line, so the raw socket
 * stream must be de-chunked before it is split into lines.
 */
final class ChunkedDecoder {
    private string $buffer = '';
    private int $remaining = 0;
    private bool $awaitingCrlf = false;
    private bool $done = false;

    /**
     * Feed raw bytes from the socket, returns the decoded payload bytes available so far.
     */
    public function decode(string $data): string {
        $this->buffer .= $data;
        $out = '';

        while (!$this->done) {
            if ($this->remaining > 0) {
                $piece = substr($this->buffer, 0, $this->remaining);
                if ($piece === '') {
                    break;
                }

                $out .= $piece;
                $this->remaining -= \strlen($piece);
                $this->buffer = substr($this->buffer, \strlen($piece));

                if ($this->remaining > 0) {
                    break;
                }

                $this->awaitingCrlf = true;
            }

            if ($this->awaitingCrlf) {
                if (\strlen($this->buffer) < 2) {
                    break;
                }

                $this->buffer = substr($this->buffer, 2);
                $this->awaitingCrlf = false;
            }

            $lineEnd = strpos($this->buffer, "\r\n");
            if ($lineEnd === false) {
                break;
            }

            // Chunk size line may carry extensions after ';'
            $sizeLine = explode(';', substr($this->buffer, 0, $lineEnd), 2)[0];
            $this->buffer = substr($this->buffer, $lineEnd + 2);
            $this->remaining = (int) hexdec(mb_trim($sizeLine));

            if ($this->remaining === 0) {
                $this->done = true;
            }
        }

        return $out;
    }

    public function isDone(): bool {
        return $this->done;
    }
}
