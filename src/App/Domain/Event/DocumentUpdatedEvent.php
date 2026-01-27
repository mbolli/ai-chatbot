<?php

declare(strict_types=1);

namespace App\Domain\Event;

final class DocumentUpdatedEvent {
    public function __construct(
        public readonly string $documentId,
        public readonly string $chatId,
        public readonly int $userId,
        public readonly string $action,
    ) {}
}
