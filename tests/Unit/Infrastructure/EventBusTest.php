<?php

declare(strict_types=1);

use App\Infrastructure\EventBus\SwooleEventBus;

it('is a singleton', function (): void {
    $bus1 = SwooleEventBus::getInstance();
    $bus2 = SwooleEventBus::getInstance();

    expect($bus1)->toBe($bus2);
});

it('subscribes and emits events to user', function (): void {
    $bus = SwooleEventBus::getInstance();
    $received = [];

    $bus->subscribe(1, function ($event) use (&$received): void {
        $received[] = $event;
    });

    $event = new stdClass();
    $event->message = 'test';

    $bus->emit(1, $event);

    expect($received)->toHaveCount(1)
        ->and($received[0]->message)->toBe('test')
    ;
});

it('does not emit to other users', function (): void {
    $bus = SwooleEventBus::getInstance();
    $received = [];

    $bus->subscribe(1, function ($event) use (&$received): void {
        $received[] = $event;
    });

    $bus->emit(2, new stdClass()); // Different user

    expect($received)->toBeEmpty();
});

it('broadcasts to all users', function (): void {
    $bus = SwooleEventBus::getInstance();
    $user1Received = [];
    $user2Received = [];

    $bus->subscribe(1, function ($event) use (&$user1Received): void {
        $user1Received[] = $event;
    });

    $bus->subscribe(2, function ($event) use (&$user2Received): void {
        $user2Received[] = $event;
    });

    $bus->broadcast(new stdClass());

    expect($user1Received)->toHaveCount(1)
        ->and($user2Received)->toHaveCount(1)
    ;
});

it('unsubscribes correctly', function (): void {
    $bus = SwooleEventBus::getInstance();
    $received = [];

    $subscriptionId = $bus->subscribe(1, function ($event) use (&$received): void {
        $received[] = $event;
    });

    $bus->unsubscribe($subscriptionId);
    $bus->emit(1, new stdClass());

    expect($received)->toBeEmpty();
});

it('counts subscribers', function (): void {
    $bus = SwooleEventBus::getInstance();

    expect($bus->getSubscriberCount())->toBe(0);

    $id1 = $bus->subscribe(1, fn () => null);
    expect($bus->getSubscriberCount())->toBe(1);

    $id2 = $bus->subscribe(1, fn () => null);
    expect($bus->getSubscriberCount())->toBe(2);

    $bus->unsubscribe($id1);
    expect($bus->getSubscriberCount())->toBe(1);
});

it('counts user-specific subscribers', function (): void {
    $bus = SwooleEventBus::getInstance();

    $bus->subscribe(1, fn () => null);
    $bus->subscribe(1, fn () => null);
    $bus->subscribe(2, fn () => null);

    expect($bus->getUserSubscriberCount(1))->toBe(2)
        ->and($bus->getUserSubscriberCount(2))->toBe(1)
        ->and($bus->getUserSubscriberCount(3))->toBe(0)
    ;
});
