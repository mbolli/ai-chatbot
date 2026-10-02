<?php

declare(strict_types=1);

namespace App\Domain\Service;

/**
 * The first limit a user has reached, with the usage that reached it.
 */
final readonly class RateLimitExceeded {
    public function __construct(
        public RateLimitType $type,
        public int $used,
        public int $limit,
        public bool $isGuest,
    ) {}
}
