<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\RateLimitService;
use App\Domain\Service\RateLimitType;
use App\Infrastructure\Persistence\SqliteRateLimitRepository;

beforeEach(function (): void {
    $this->pdo = createTestPdo();
    $this->pdo->exec("INSERT INTO users (id, email, is_guest) VALUES (1, 'guest_1@guest.local', 1), (2, 'user@example.com', 0)");
    $this->rateLimitRepo = new SqliteRateLimitRepository($this->pdo);

    $this->userRepo = \Mockery::mock(UserRepositoryInterface::class);
    $this->userRepo->shouldReceive('findById')->with(1)->andReturn(
        new User(id: 1, email: 'guest_1@guest.local', passwordHash: '', roles: ['guest'], details: [], isGuest: true),
    );
    $this->userRepo->shouldReceive('findById')->with(2)->andReturn(
        new User(id: 2, email: 'user@example.com', passwordHash: 'hashed', roles: ['user'], details: [], isGuest: false),
    );

    $this->now = strtotime('2026-03-10 12:00:00');
    $this->service = fn (array $limits = []): RateLimitService => new RateLimitService(
        rateLimitRepository: $this->rateLimitRepo,
        userRepository: $this->userRepo,
        guestDailyLimit: $limits['guestDaily'] ?? 20,
        registeredDailyLimit: $limits['registeredDaily'] ?? 100,
        guestHourlyLimit: $limits['guestHourly'] ?? 0,
        registeredHourlyLimit: $limits['registeredHourly'] ?? 0,
        guestDailyTokenLimit: $limits['guestTokens'] ?? 0,
        registeredDailyTokenLimit: $limits['registeredTokens'] ?? 0,
        clock: fn (): int => $this->now,
    );
});

afterEach(function (): void {
    \Mockery::close();
});

it('allows guest user under limit to send message', function (): void {
    $service = ($this->service)(['guestDaily' => 3]);
    $service->recordMessage(1);
    $service->recordMessage(1);

    expect($service->canSendMessage(1))->toBeTrue();
});

it('blocks guest user at the daily limit', function (): void {
    $service = ($this->service)(['guestDaily' => 2]);
    $service->recordMessage(1);
    $service->recordMessage(1);

    $exceeded = $service->exceededLimit(1);

    expect($service->canSendMessage(1))->toBeFalse()
        ->and($exceeded?->type)->toBe(RateLimitType::DailyMessages)
        ->and($exceeded?->used)->toBe(2)
        ->and($exceeded?->limit)->toBe(2)
        ->and($exceeded?->isGuest)->toBeTrue()
    ;
});

it('allows registered user higher limit', function (): void {
    $service = ($this->service)(['guestDaily' => 1, 'registeredDaily' => 5]);
    $service->recordMessage(2);
    $service->recordMessage(2);

    expect($service->canSendMessage(2))->toBeTrue();
});

it('resets the daily count on the next day', function (): void {
    $service = ($this->service)(['guestDaily' => 1]);
    $service->recordMessage(1);
    expect($service->canSendMessage(1))->toBeFalse();

    $this->now += 86400;
    expect($service->canSendMessage(1))->toBeTrue();
});

it('enforces the hourly request limit over a sliding window', function (): void {
    $service = ($this->service)(['guestHourly' => 3]);

    $service->recordMessage(1);
    $this->now += 1200;
    $service->recordMessage(1);
    $this->now += 1200;
    $service->recordMessage(1);

    $exceeded = $service->exceededLimit(1);
    expect($exceeded?->type)->toBe(RateLimitType::HourlyRequests)
        ->and($exceeded?->used)->toBe(3)
        ->and($exceeded?->limit)->toBe(3)
    ;

    // The first request leaves the window one hour after it was made
    $this->now += 1199;
    expect($service->canSendMessage(1))->toBeFalse();
    $this->now += 2;
    expect($service->canSendMessage(1))->toBeTrue();
});

it('applies the hourly limit per user type', function (): void {
    $service = ($this->service)(['guestHourly' => 1, 'registeredHourly' => 2]);

    $service->recordMessage(1);
    $service->recordMessage(2);

    expect($service->canSendMessage(1))->toBeFalse()
        ->and($service->canSendMessage(2))->toBeTrue()
    ;
});

it('treats an hourly limit of 0 as unlimited', function (): void {
    $service = ($this->service)(['guestHourly' => 0, 'guestDaily' => 100]);

    for ($i = 0; $i < 50; ++$i) {
        $service->recordMessage(1);
    }

    expect($service->canSendMessage(1))->toBeTrue();
});

it('blocks once the daily token tally reaches the limit', function (): void {
    $service = ($this->service)(['guestTokens' => 1000]);

    $service->recordTokens(1, 600);
    expect($service->canSendMessage(1))->toBeTrue();

    $service->recordTokens(1, 500);
    $exceeded = $service->exceededLimit(1);

    expect($exceeded?->type)->toBe(RateLimitType::DailyTokens)
        ->and($exceeded?->used)->toBe(1100)
        ->and($exceeded?->limit)->toBe(1000)
    ;
    expect($this->rateLimitRepo->getTokenCount(1, '2026-03-10'))->toBe(1100);

    $this->now += 86400;
    expect($service->canSendMessage(1))->toBeTrue();
});

it('treats a token limit of 0 as unlimited', function (): void {
    $service = ($this->service)();
    $service->recordTokens(1, 10_000_000);

    expect($service->canSendMessage(1))->toBeTrue();
});

it('keeps message and token tallies in one row per day', function (): void {
    $service = ($this->service)();
    $service->recordMessage(2);
    $service->recordTokens(2, 250);
    $service->recordMessage(2);
    $service->recordTokens(2, 50);

    expect($this->rateLimitRepo->getMessageCount(2, '2026-03-10'))->toBe(2)
        ->and($this->rateLimitRepo->getTokenCount(2, '2026-03-10'))->toBe(300)
    ;
});

it('returns correct usage info for guest', function (): void {
    $service = ($this->service)(['guestDaily' => 20]);
    for ($i = 0; $i < 15; ++$i) {
        $service->recordMessage(1);
    }

    expect($service->getUsageInfo(1))->toMatchArray([
        'used' => 15,
        'limit' => 20,
        'remaining' => 5,
        'is_guest' => true,
    ]);
});

it('returns correct usage info for registered user', function (): void {
    $service = ($this->service)(['registeredDaily' => 100]);
    for ($i = 0; $i < 50; ++$i) {
        $service->recordMessage(2);
    }

    expect($service->getUsageInfo(2))->toMatchArray([
        'used' => 50,
        'limit' => 100,
        'remaining' => 50,
        'is_guest' => false,
    ]);
});
