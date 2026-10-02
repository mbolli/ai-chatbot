<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Readable reasoning streamed while an assistant message is generated.
 */
final class MessageThinkingEvent {
    public function __construct(
        public readonly string $chatId,
        public readonly string $messageId,
        public readonly int $userId,
        public readonly string $fullThinking,
    ) {}
}
