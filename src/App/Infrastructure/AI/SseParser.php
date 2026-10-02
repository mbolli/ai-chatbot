<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

/**
 * Incremental Server-Sent Events parser: feed de-chunked bytes as they arrive, get complete events back.
 */
final class SseParser {
    private string $buffer = '';
    private string $event = '';

    /** @var list<string> */
    private array $data = [];

    /**
     * @return list<SseEvent>
     */
    public function feed(string $bytes): array {
        $this->buffer .= $bytes;
        $events = [];

        while (($lineEnd = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $lineEnd), "\r");
            $this->buffer = substr($this->buffer, $lineEnd + 1);

            if ($line === '') {
                $event = $this->dispatch();
                if ($event !== null) {
                    $events[] = $event;
                }

                continue;
            }

            if (str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = str_contains($line, ':') ? explode(':', $line, 2) : [$line, ''];
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }

            if ($field === 'data') {
                $this->data[] = $value;
            } elseif ($field === 'event') {
                $this->event = $value;
            }
        }

        return $events;
    }

    /**
     * Returns the event left unterminated when the connection closed, if any.
     */
    public function flush(): ?SseEvent {
        return $this->feed("\n")[0] ?? $this->dispatch();
    }

    private function dispatch(): ?SseEvent {
        $event = $this->data === [] ? null : new SseEvent(implode("\n", $this->data), $this->event !== '' ? $this->event : 'message');
        $this->event = '';
        $this->data = [];

        return $event;
    }
}
