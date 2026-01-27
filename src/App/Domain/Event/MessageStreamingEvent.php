<?php

declare(strict_types=1);

namespace App\Domain\Event;

final class MessageStreamingEvent {
    public function __construct(
        public readonly string $chatId,
        public readonly string $messageId,
        public readonly int $userId,
        public readonly string $chunk,
        public readonly bool $isComplete = false,
    ) {}
}
