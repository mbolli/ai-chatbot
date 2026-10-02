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
     * Get the tokens a user consumed on a specific date.
     */
    public function getTokenCount(int $userId, string $date): int;

    /**
     * Add consumed tokens to a user's tally for a specific date.
     */
    public function addTokens(int $userId, string $date, int $tokens): void;

    /**
     * Log one AI request at a Unix timestamp. The user's requests older than an hour before it are pruned.
     */
    public function recordRequest(int $userId, int $timestamp): void;

    /**
     * Count logged requests at or after a Unix timestamp.
     */
    public function countRequestsSince(int $userId, int $since): int;

    /**
     * Reset/delete old rate limit records (cleanup).
     */
    public function cleanupOldRecords(int $daysToKeep = 7): int;
}
