<?php

declare(strict_types=1);

namespace App\Domain\Event;

final class DocumentUpdatedEvent {
    public function __construct(
        public readonly string $documentId,
        public readonly string $chatId,
        public readonly int $userId,
        public readonly string $action,
        public readonly ?int $version = null,
        public readonly ?string $kind = null,
        public readonly ?string $language = null,
    ) {}
}
