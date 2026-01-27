<?php

declare(strict_types=1);

namespace App\Infrastructure\EventBus;

use Ramsey\Uuid\Uuid;

/**
 * Swoole-based event bus for real-time SSE updates.
 * Uses a singleton pattern since Swoole workers share memory via Swoole\Table.
 */
final class SwooleEventBus implements EventBusInterface {
    private static ?self $instance = null;

    /** @var array<string, array{userId: int, callback: callable}> */
    private array $subscribers = [];

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function subscribe(int $userId, callable $callback): string {
        $subscriptionId = Uuid::uuid4()->toString();

        $this->subscribers[$subscriptionId] = [
            'userId' => $userId,
            'callback' => $callback,
        ];

        return $subscriptionId;
    }

    public function unsubscribe(string $subscriptionId): void {
        unset($this->subscribers[$subscriptionId]);
    }

    public function emit(int $userId, object $event): void {
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber['userId'] === $userId) {
                try {
                    ($subscriber['callback'])($event);
                } catch (\Throwable $e) {
                    // Log error but continue with other subscribers
                    error_log('EventBus error: ' . $e->getMessage());
                }
            }
        }
    }

    public function broadcast(object $event): void {
        foreach ($this->subscribers as $subscriber) {
            try {
                ($subscriber['callback'])($event);
            } catch (\Throwable $e) {
                error_log('EventBus broadcast error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get the count of active subscribers.
     */
    public function getSubscriberCount(): int {
        return \count($this->subscribers);
    }

    /**
     * Get subscriber count for a specific user.
     */
    public function getUserSubscriberCount(int $userId): int {
        $count = 0;
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber['userId'] === $userId) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Reset the instance (for testing).
     */
    public static function reset(): void {
        if (self::$instance !== null) {
            self::$instance->subscribers = [];
        }
        self::$instance = null;
    }
}
