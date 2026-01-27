<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Model\Vote;
use App\Domain\Repository\VoteRepositoryInterface;

final class SqliteVoteRepository implements VoteRepositoryInterface {
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function find(string $messageId, int $userId): ?Vote {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM votes WHERE message_id = :message_id AND user_id = :user_id'
        );
        $stmt->execute(['message_id' => $messageId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return Vote::fromArray($row);
    }

    /**
     * @return list<Vote>
     */
    public function findByChat(string $chatId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM votes WHERE chat_id = :chat_id');
        $stmt->execute(['chat_id' => $chatId]);

        $votes = [];
        while ($row = $stmt->fetch()) {
            $votes[] = Vote::fromArray($row);
        }

        return $votes;
    }

    /**
     * @return list<Vote>
     */
    public function findByMessage(string $messageId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM votes WHERE message_id = :message_id');
        $stmt->execute(['message_id' => $messageId]);

        $votes = [];
        while ($row = $stmt->fetch()) {
            $votes[] = Vote::fromArray($row);
        }

        return $votes;
    }

    public function save(Vote $vote): void {
        // Use REPLACE to handle upsert (unique constraint on message_id, user_id)
        $stmt = $this->pdo->prepare(
            'REPLACE INTO votes (chat_id, message_id, user_id, is_upvote, created_at)
             VALUES (:chat_id, :message_id, :user_id, :is_upvote, :created_at)'
        );

        $stmt->execute([
            'chat_id' => $vote->chatId,
            'message_id' => $vote->messageId,
            'user_id' => $vote->userId,
            'is_upvote' => $vote->isUpvote ? 1 : 0,
            'created_at' => $vote->createdAt->getTimestamp(),
        ]);
    }

    public function delete(string $messageId, int $userId): void {
        $stmt = $this->pdo->prepare(
            'DELETE FROM votes WHERE message_id = :message_id AND user_id = :user_id'
        );
        $stmt->execute(['message_id' => $messageId, 'user_id' => $userId]);
    }

    public function deleteByChat(string $chatId): void {
        $stmt = $this->pdo->prepare('DELETE FROM votes WHERE chat_id = :chat_id');
        $stmt->execute(['chat_id' => $chatId]);
    }
}
