<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\Model\User;
use App\Domain\Repository\RateLimitRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\RateLimitService;

beforeEach(function (): void {
    $this->rateLimitRepo = \Mockery::mock(RateLimitRepositoryInterface::class);
    $this->userRepo = \Mockery::mock(UserRepositoryInterface::class);
});

afterEach(function (): void {
    \Mockery::close();
});

it('allows guest user under limit to send message', function (): void {
    $guestUser = new User(
        id: 1,
        email: 'guest_123@guest.local',
        passwordHash: '',
        roles: ['guest'],
        details: [],
        isGuest: true,
    );

    $this->userRepo->shouldReceive('findById')
        ->with(1)
        ->andReturn($guestUser)
    ;

    $this->rateLimitRepo->shouldReceive('isUnderLimit')
        ->with(1, date('Y-m-d'), 20)
        ->andReturn(true)
    ;

    $service = new RateLimitService(
        rateLimitRepository: $this->rateLimitRepo,
        userRepository: $this->userRepo,
        guestDailyLimit: 20,
        registeredDailyLimit: 100,
    );

    expect($service->canSendMessage(1))->toBeTrue();
});

it('blocks guest user at limit', function (): void {
    $guestUser = new User(
        id: 1,
        email: 'guest_123@guest.local',
        passwordHash: '',
        roles: ['guest'],
        details: [],
        isGuest: true,
    );

    $this->userRepo->shouldReceive('findById')
        ->with(1)
        ->andReturn($guestUser)
    ;

    $this->rateLimitRepo->shouldReceive('isUnderLimit')
        ->with(1, date('Y-m-d'), 20)
        ->andReturn(false)
    ;

    $service = new RateLimitService(
        rateLimitRepository: $this->rateLimitRepo,
        userRepository: $this->userRepo,
        guestDailyLimit: 20,
        registeredDailyLimit: 100,
    );

    expect($service->canSendMessage(1))->toBeFalse();
});

it('allows registered user higher limit', function (): void {
    $registeredUser = new User(
        id: 2,
        email: 'user@example.com',
        passwordHash: 'hashed',
        roles: ['user'],
        details: [],
        isGuest: false,
    );

    $this->userRepo->shouldReceive('findById')
        ->with(2)
        ->andReturn($registeredUser)
    ;

    $this->rateLimitRepo->shouldReceive('isUnderLimit')
        ->with(2, date('Y-m-d'), 100)
        ->andReturn(true)
    ;

    $service = new RateLimitService(
        rateLimitRepository: $this->rateLimitRepo,
        userRepository: $this->userRepo,
        guestDailyLimit: 20,
        registeredDailyLimit: 100,
    );

    expect($service->canSendMessage(2))->toBeTrue();
});

it('records message when sent', function (): void {
    $this->rateLimitRepo->shouldReceive('incrementMessageCount')
        ->with(1, date('Y-m-d'))
        ->once()
    ;

    $service = new RateLimitService(
        rateLimitRepository: $this->rateLimitRepo,
        userRepository: $this->userRepo,
    );

    $service->recordMessage(1);
});

it('returns correct usage info for guest', function (): void {
    $guestUser = new User(
        id: 1,
        email: 'guest_123@guest.local',
        passwordHash: '',
        roles: ['guest'],
        details: [],
        isGuest: true,
    );

    $this->userRepo->shouldReceive('findById')
        ->with(1)
        ->andReturn($guestUser)
    ;

    $this->rateLimitRepo->shouldReceive('getMessageCount')
        ->with(1, date('Y-m-d'))
        ->andReturn(15)
    ;

    $service = new RateLimitService(
        rateLimitRepository: $this->rateLimitRepo,
        userRepository: $this->userRepo,
        guestDailyLimit: 20,
        registeredDailyLimit: 100,
    );

    $info = $service->getUsageInfo(1);

    expect($info)->toMatchArray([
        'used' => 15,
        'limit' => 20,
        'remaining' => 5,
        'is_guest' => true,
    ]);
});

it('returns correct usage info for registered user', function (): void {
    $registeredUser = new User(
        id: 2,
        email: 'user@example.com',
        passwordHash: 'hashed',
        roles: ['user'],
        details: [],
        isGuest: false,
    );

    $this->userRepo->shouldReceive('findById')
        ->with(2)
        ->andReturn($registeredUser)
    ;

    $this->rateLimitRepo->shouldReceive('getMessageCount')
        ->with(2, date('Y-m-d'))
        ->andReturn(50)
    ;

    $service = new RateLimitService(
        rateLimitRepository: $this->rateLimitRepo,
        userRepository: $this->userRepo,
        guestDailyLimit: 20,
        registeredDailyLimit: 100,
    );

    $info = $service->getUsageInfo(2);

    expect($info)->toMatchArray([
        'used' => 50,
        'limit' => 100,
        'remaining' => 50,
        'is_guest' => false,
    ]);
});
