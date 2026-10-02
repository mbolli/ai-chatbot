<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Repository\RateLimitRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;

/**
 * Per-user limits: messages per day, AI requests per sliding hour and tokens per day.
 * An hourly or token limit of 0 disables it; a daily message limit of 0 blocks the user type.
 */
final class RateLimitService {
    private readonly \Closure $clock;

    /**
     * @param null|\Closure(): int $clock Returns the current Unix timestamp
     */
    public function __construct(
        private readonly RateLimitRepositoryInterface $rateLimitRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly int $guestDailyLimit = 20,
        private readonly int $registeredDailyLimit = 100,
        private readonly int $guestHourlyLimit = 0,
        private readonly int $registeredHourlyLimit = 0,
        private readonly int $guestDailyTokenLimit = 0,
        private readonly int $registeredDailyTokenLimit = 0,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Check if user can send a message (under every limit).
     */
    public function canSendMessage(int $userId): bool {
        return $this->exceededLimit($userId) === null;
    }

    /**
     * The first limit the user has reached, or null when another request is allowed.
     *
     * The token limit is checked before a request, so the request that crosses it still completes.
     */
    public function exceededLimit(int $userId): ?RateLimitExceeded {
        $isGuest = $this->isGuestUser($userId);
        $today = $this->getToday();

        $dailyLimit = $isGuest ? $this->guestDailyLimit : $this->registeredDailyLimit;
        $used = $this->rateLimitRepository->getMessageCount($userId, $today);
        if ($used >= $dailyLimit) {
            return new RateLimitExceeded(RateLimitType::DailyMessages, $used, $dailyLimit, $isGuest);
        }

        $tokenLimit = $isGuest ? $this->guestDailyTokenLimit : $this->registeredDailyTokenLimit;
        if ($tokenLimit > 0) {
            $tokens = $this->rateLimitRepository->getTokenCount($userId, $today);
            if ($tokens >= $tokenLimit) {
                return new RateLimitExceeded(RateLimitType::DailyTokens, $tokens, $tokenLimit, $isGuest);
            }
        }

        $hourlyLimit = $isGuest ? $this->guestHourlyLimit : $this->registeredHourlyLimit;
        if ($hourlyLimit > 0) {
            $requests = $this->rateLimitRepository->countRequestsSince($userId, ($this->clock)() - 3600);
            if ($requests >= $hourlyLimit) {
                return new RateLimitExceeded(RateLimitType::HourlyRequests, $requests, $hourlyLimit, $isGuest);
            }
        }

        return null;
    }

    /**
     * Record that a user sent a message (counts toward the daily and hourly limits).
     */
    public function recordMessage(int $userId): void {
        $this->rateLimitRepository->incrementMessageCount($userId, $this->getToday());
        $this->rateLimitRepository->recordRequest($userId, ($this->clock)());
    }

    /**
     * Add tokens consumed by a response to today's tally.
     */
    public function recordTokens(int $userId, int $tokens): void {
        if ($tokens > 0) {
            $this->rateLimitRepository->addTokens($userId, $this->getToday(), $tokens);
        }
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
        return $this->isGuestUser($userId) ? $this->guestDailyLimit : $this->registeredDailyLimit;
    }

    /**
     * Check if user is a guest.
     */
    public function isGuestUser(int $userId): bool {
        $user = $this->userRepository->findById($userId);

        return $user === null || $user->isGuest;
    }

    private function getToday(): string {
        return date('Y-m-d', ($this->clock)());
    }
}
