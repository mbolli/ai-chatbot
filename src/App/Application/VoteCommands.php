<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Event\VoteUpdatedEvent;
use App\Domain\Model\Vote;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Repository\VoteRepositoryInterface;
use App\Infrastructure\EventBus\EventBusInterface;

/**
 * Up- and downvotes on assistant replies. Methods return an HTTP-like status code.
 */
final class VoteCommands {
    public function __construct(
        private readonly VoteRepositoryInterface $voteRepository,
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly EventBusInterface $eventBus,
    ) {}

    /**
     * Cast a vote; casting the same vote again removes it.
     */
    public function vote(int $userId, string $chatId, string $messageId, bool $isUpvote): int {
        $chat = $this->chatRepository->find($chatId);
        if ($chat === null) {
            return 404;
        }
        if (!$chat->isOwnedBy($userId)) {
            return 403;
        }

        $message = $this->messageRepository->find($messageId);
        if ($message === null || $message->chatId !== $chatId) {
            return 404;
        }
        if (!$message->isAssistant()) {
            return 400;
        }

        $existing = $this->voteRepository->find($messageId, $userId);
        if ($existing !== null && $existing->isUpvote === $isUpvote) {
            $this->voteRepository->delete($messageId, $userId);
            $state = null;
        } else {
            $this->voteRepository->save($isUpvote
                ? Vote::upvote($chatId, $messageId, $userId)
                : Vote::downvote($chatId, $messageId, $userId));
            $state = $isUpvote;
        }

        $this->eventBus->emit($userId, new VoteUpdatedEvent($chatId, $messageId, $userId, $state));

        return 204;
    }
}
