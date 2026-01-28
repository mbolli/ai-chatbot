<?php

declare(strict_types=1);

namespace App\Domain\Event;

final readonly class RateLimitExceededEvent {
    public function __construct(
        public int $userId,
        public string $chatId,
        public int $used,
        public int $limit,
        public bool $isGuest,
    ) {}
}
