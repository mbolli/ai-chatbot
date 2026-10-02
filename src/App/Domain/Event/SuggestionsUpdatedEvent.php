<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * The pending suggestions of a document changed.
 */
final readonly class SuggestionsUpdatedEvent {
    public const string ACTION_REQUESTED = 'requested';
    public const string ACTION_DISMISSED = 'dismissed';

    public function __construct(
        public string $documentId,
        public string $chatId,
        public int $userId,
        public string $action,
    ) {}
}
