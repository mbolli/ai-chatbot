<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Vote;

interface VoteRepositoryInterface {
    public function find(string $messageId, int $userId): ?Vote;

    /**
     * @return list<Vote>
     */
    public function findByChat(string $chatId): array;

    /**
     * @return list<Vote>
     */
    public function findByMessage(string $messageId): array;

    public function save(Vote $vote): void;

    public function delete(string $messageId, int $userId): void;

    public function deleteByChat(string $chatId): void;
}
