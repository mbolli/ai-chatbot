<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Repository\RateLimitRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;

final class RateLimitService {
    public function __construct(
        private readonly RateLimitRepositoryInterface $rateLimitRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly int $guestDailyLimit = 20,
        private readonly int $registeredDailyLimit = 100,
    ) {}

    /**
     * Check if user can send a message (under daily limit).
     */
    public function canSendMessage(int $userId): bool {
        $limit = $this->getDailyLimit($userId);
        $today = $this->getToday();

        return $this->rateLimitRepository->isUnderLimit($userId, $today, $limit);
    }

    /**
     * Record that a user sent a message.
     */
    public function recordMessage(int $userId): void {
        $today = $this->getToday();
        $this->rateLimitRepository->incrementMessageCount($userId, $today);
    }

    /**
     * Get the remaining messages for today.
     */
    public function getRemainingMessages(int $userId): int {
        $limit = $this->getDailyLimit($userId);
        $today = $this->getToday();
        $used = $this->rateLimitRepository->getMessageCount($userId, $today);

        return max(0, $limit - $used);
    }

    /**
     * Get current usage info for a user.
     *
     * @return array{used: int, limit: int, remaining: int, is_guest: bool}
     */
    public function getUsageInfo(int $userId): array {
        $limit = $this->getDailyLimit($userId);
        $today = $this->getToday();
        $used = $this->rateLimitRepository->getMessageCount($userId, $today);
        $user = $this->userRepository->findById($userId);

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'is_guest' => $user === null || $user->isGuest,
        ];
    }

    /**
     * Get the daily limit for a user based on their account type.
     */
    public function getDailyLimit(int $userId): int {
        $user = $this->userRepository->findById($userId);

        if ($user === null || $user->isGuest) {
            return $this->guestDailyLimit;
        }

        return $this->registeredDailyLimit;
    }

    /**
     * Check if user is a guest.
     */
    public function isGuestUser(int $userId): bool {
        $user = $this->userRepository->findById($userId);

        return $user === null || $user->isGuest;
    }

    private function getToday(): string {
        return date('Y-m-d');
    }
}
