<?php

declare(strict_types=1);

namespace App\Infrastructure\EventBus;

interface EventBusInterface {
    /**
     * Subscribe to events for a specific user.
     */
    public function subscribe(int $userId, callable $callback): string;

    /**
     * Unsubscribe from events.
     */
    public function unsubscribe(string $subscriptionId): void;

    /**
     * Emit an event to all subscribers for a user.
     */
    public function emit(int $userId, object $event): void;

    /**
     * Emit an event to all subscribers (broadcast).
     */
    public function broadcast(object $event): void;
}
