<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\RateLimitRepositoryInterface;

final class SqliteRateLimitRepository implements RateLimitRepositoryInterface {
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function getMessageCount(int $userId, string $date): int {
        $stmt = $this->pdo->prepare(
            'SELECT message_count FROM rate_limits WHERE user_id = :user_id AND date = :date',
        );
        $stmt->execute([
            'user_id' => $userId,
            'date' => $date,
        ]);

        $result = $stmt->fetchColumn();

        return $result !== false ? (int) $result : 0;
    }

    public function incrementMessageCount(int $userId, string $date): void {
        // Use INSERT OR REPLACE (upsert) pattern for SQLite
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (user_id, date, message_count)
             VALUES (:user_id, :date, 1)
             ON CONFLICT(user_id, date) DO UPDATE SET message_count = message_count + 1',
        );
        $stmt->execute([
            'user_id' => $userId,
            'date' => $date,
        ]);
    }

    public function isUnderLimit(int $userId, string $date, int $limit): bool {
        return $this->getMessageCount($userId, $date) < $limit;
    }

    public function cleanupOldRecords(int $daysToKeep = 7): int {
        $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));

        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE date < :cutoff_date');
        $stmt->execute(['cutoff_date' => $cutoffDate]);

        return $stmt->rowCount();
    }
}
