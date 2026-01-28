<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Swoole\Table;

/**
 * Manages active AI streaming sessions for stop generation feature.
 * Uses Swoole Table for shared state across coroutines.
 */
final class StreamingSessionManager {
    private const int TABLE_SIZE = 1024;
    private static ?Table $table = null;

    public static function getTable(): Table {
        if (self::$table === null) {
            self::$table = new Table(self::TABLE_SIZE);
            self::$table->column('chat_id', Table::TYPE_STRING, 64);
            self::$table->column('user_id', Table::TYPE_INT);
            self::$table->column('message_id', Table::TYPE_STRING, 64);
            self::$table->column('stop_requested', Table::TYPE_INT, 1);
            self::$table->column('created_at', Table::TYPE_INT);
            self::$table->create();
        }

        return self::$table;
    }

    /**
     * Start a streaming session.
     */
    public function startSession(string $chatId, int $userId, string $messageId): string {
        $sessionId = $this->generateSessionId($chatId, $userId);
        $table = self::getTable();

        $table->set($sessionId, [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message_id' => $messageId,
            'stop_requested' => 0,
            'created_at' => time(),
        ]);

        return $sessionId;
    }

    /**
     * Request stop for a streaming session.
     */
    public function requestStop(string $chatId, int $userId): bool {
        $sessionId = $this->generateSessionId($chatId, $userId);
        $table = self::getTable();

        if (!$table->exists($sessionId)) {
            return false;
        }

        $table->set($sessionId, [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message_id' => $table->get($sessionId, 'message_id'),
            'stop_requested' => 1,
            'created_at' => $table->get($sessionId, 'created_at'),
        ]);

        return true;
    }

    /**
     * Check if stop was requested for a session.
     */
    public function isStopRequested(string $chatId, int $userId): bool {
        $sessionId = $this->generateSessionId($chatId, $userId);
        $table = self::getTable();

        if (!$table->exists($sessionId)) {
            return false;
        }

        return (bool) $table->get($sessionId, 'stop_requested');
    }

    /**
     * End a streaming session.
     */
    public function endSession(string $chatId, int $userId): void {
        $sessionId = $this->generateSessionId($chatId, $userId);
        $table = self::getTable();
        $table->del($sessionId);
    }

    /**
     * Check if there is an active streaming session.
     */
    public function hasActiveSession(string $chatId, int $userId): bool {
        $sessionId = $this->generateSessionId($chatId, $userId);

        return self::getTable()->exists($sessionId);
    }

    /**
     * Get session info.
     *
     * @return null|array{chat_id: string, user_id: int, message_id: string, stop_requested: int, created_at: int}
     */
    public function getSession(string $chatId, int $userId): ?array {
        $sessionId = $this->generateSessionId($chatId, $userId);
        $table = self::getTable();

        if (!$table->exists($sessionId)) {
            return null;
        }

        $data = $table->get($sessionId);

        return $data !== false ? $data : null;
    }

    /**
     * Clean up stale sessions (older than 5 minutes).
     */
    public function cleanupStaleSessions(): int {
        $table = self::getTable();
        $staleTime = time() - 300;
        $cleaned = 0;

        foreach ($table as $key => $row) {
            if ($row['created_at'] < $staleTime) {
                $table->del($key);
                ++$cleaned;
            }
        }

        return $cleaned;
    }

    private function generateSessionId(string $chatId, int $userId): string {
        return "stream:{$userId}:{$chatId}";
    }
}
