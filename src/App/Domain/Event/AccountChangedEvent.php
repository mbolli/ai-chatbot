<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * A browser session signed in, out or upgraded its account; $userId is the user it acted as before.
 */
final readonly class AccountChangedEvent {
    public function __construct(
        public int $userId,
    ) {}
}
