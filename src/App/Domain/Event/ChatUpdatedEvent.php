<?php

declare(strict_types=1);

namespace App\Domain\Event;

final class ChatUpdatedEvent {
    public function __construct(
        public readonly string $chatId,
        public readonly int $userId,
        public readonly string $action,
        public readonly ?string $messageId = null,
        public readonly ?string $messageRole = null,
        public readonly ?string $messageContent = null,
        public readonly ?string $title = null,
        public readonly ?string $redirectUrl = null,
        public readonly bool $clearMessage = false,
    ) {}
}
