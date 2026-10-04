<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

/**
 * Upgrades an existing database in place, keyed on PRAGMA user_version.
 *
 * data/schema.sql stays the fresh-install source of truth and sets user_version to the latest version,
 * so a fresh database never runs these steps. Add a step here whenever schema.sql changes.
 */
final class SchemaMigrator {
    /**
     * @var array<int, list<string>>
     */
    private const array MIGRATIONS = [
        1 => [
            'ALTER TABLE "rate_limits" ADD COLUMN "token_count" INTEGER NOT NULL DEFAULT 0',
            'CREATE TABLE "rate_limit_requests" (
                "user_id" INTEGER NOT NULL,
                "created_at" INTEGER NOT NULL,
                FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
            )',
            'CREATE INDEX "rate_limit_requests_user_id_created_at_ix" ON "rate_limit_requests" ("user_id", "created_at")',
            'CREATE TABLE "message_usage" (
                "message_id" TEXT PRIMARY KEY NOT NULL,
                "user_id" INTEGER NOT NULL,
                "model" TEXT NOT NULL,
                "stop_reason" TEXT NOT NULL,
                "input_tokens" INTEGER NOT NULL DEFAULT 0,
                "output_tokens" INTEGER NOT NULL DEFAULT 0,
                "cache_read_tokens" INTEGER NOT NULL DEFAULT 0,
                "cache_write_tokens" INTEGER NOT NULL DEFAULT 0,
                "estimated" INTEGER NOT NULL DEFAULT 0 CHECK ("estimated" >= 0 AND "estimated" <= 1),
                "created_at" INTEGER NOT NULL,
                FOREIGN KEY ("message_id") REFERENCES "messages" ("id") ON DELETE CASCADE
            )',
            'CREATE INDEX "message_usage_user_id_created_at_ix" ON "message_usage" ("user_id", "created_at")',
        ],
        // The UNIQUE constraint already indexes users.email
        2 => [
            'DROP INDEX IF EXISTS "users_email_ix"',
        ],
    ];

    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public static function latestVersion(): int {
        return max(array_keys(self::MIGRATIONS));
    }

    public function currentVersion(): int {
        $stmt = $this->pdo->query('PRAGMA user_version');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * Applies every pending step, each in its own transaction together with its version bump.
     *
     * @return list<int> The versions that were applied
     */
    public function migrate(): array {
        $applied = [];

        foreach (self::MIGRATIONS as $version => $statements) {
            if ($version <= $this->currentVersion()) {
                continue;
            }

            $this->pdo->beginTransaction();

            try {
                foreach ($statements as $sql) {
                    $this->pdo->exec($sql);
                }
                $this->pdo->exec('PRAGMA user_version = ' . $version);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();

                throw new \RuntimeException("Migration to version {$version} failed: " . $e->getMessage(), 0, $e);
            }

            $applied[] = $version;
        }

        return $applied;
    }
}
