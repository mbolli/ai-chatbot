<?php

declare(strict_types=1);

namespace App\Domain\Service;

enum RateLimitType: string {
    case DailyMessages = 'daily_messages';
    case DailyTokens = 'daily_tokens';
    case HourlyRequests = 'hourly_requests';
}
