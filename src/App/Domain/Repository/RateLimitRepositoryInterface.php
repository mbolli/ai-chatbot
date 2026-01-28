<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface RateLimitRepositoryInterface {
    /**
     * Get the message count for a user on a specific date.
     */
    public function getMessageCount(int $userId, string $date): int;

    /**
     * Increment the message count for a user on a specific date.
     */
    public function incrementMessageCount(int $userId, string $date): void;

    /**
     * Check if a user is under the daily message limit.
     */
    public function isUnderLimit(int $userId, string $date, int $limit): bool;

    /**
     * Reset/delete old rate limit records (cleanup).
     */
    public function cleanupOldRecords(int $daysToKeep = 7): int;
}
