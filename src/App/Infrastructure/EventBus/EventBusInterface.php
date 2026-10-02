<?php

declare(strict_types=1);

namespace App\Infrastructure\EventBus;

/**
 * Delivers domain events to whatever renders them for a user.
 */
interface EventBusInterface {
    public function emit(int $userId, object $event): void;
}
