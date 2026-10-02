<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

final readonly class SseEvent {
    public function __construct(
        public string $data,
        public string $event = 'message',
    ) {}
}
