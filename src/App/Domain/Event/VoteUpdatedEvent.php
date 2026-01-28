<?php

declare(strict_types=1);

namespace App\Domain\Event;

final class VoteUpdatedEvent {
    public function __construct(
        public readonly string $chatId,
        public readonly string $messageId,
        public readonly int $userId,
        public readonly ?bool $vote, // true=upvote, false=downvote, null=removed
    ) {}
}
