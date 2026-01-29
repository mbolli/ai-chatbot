<?php

declare(strict_types=1);

use App\Domain\Model\Chat;

it('creates a chat with default values', function (): void {
    $chat = Chat::create(userId: 1);

    expect($chat->id)->toBeUuid()
        ->and($chat->userId)->toBe(1)
        ->and($chat->title)->toBeNull()
        ->and($chat->model)->toBe('claude-3-5-sonnet')
        ->and($chat->visibility)->toBe('private')
    ;
});

it('creates a chat with custom values', function (): void {
    $chat = Chat::create(
        userId: 42,
        model: 'gpt-4',
        visibility: 'public',
        title: 'Test Chat',
    );

    expect($chat->userId)->toBe(42)
        ->and($chat->model)->toBe('gpt-4')
        ->and($chat->visibility)->toBe('public')
        ->and($chat->title)->toBe('Test Chat')
    ;
});

it('updates the title', function (): void {
    $chat = Chat::create(userId: 1);
    $updated = $chat->updateTitle('New Title');

    expect($updated->title)->toBe('New Title')
        ->and($updated->id)->toBe($chat->id)
        ->and($updated->updatedAt)->toBeGreaterThanOrEqual($chat->updatedAt)
    ;
});

it('updates visibility', function (): void {
    $chat = Chat::create(userId: 1);
    $updated = $chat->updateVisibility('public');

    expect($updated->visibility)->toBe('public')
        ->and($updated->isPublic())->toBeTrue()
    ;
});

it('checks ownership', function (): void {
    $chat = Chat::create(userId: 1);

    expect($chat->isOwnedBy(1))->toBeTrue()
        ->and($chat->isOwnedBy(2))->toBeFalse()
    ;
});

it('serializes to array', function (): void {
    $chat = Chat::create(userId: 1, title: 'Test');
    $array = $chat->toArray();

    expect($array)->toHaveKeys(['id', 'userId', 'title', 'model', 'visibility', 'createdAt', 'updatedAt'])
        ->and($array['title'])->toBe('Test')
        ->and($array['userId'])->toBe(1)
    ;
});

it('deserializes from array', function (): void {
    $now = time();
    $data = [
        'id' => 'test-id-123',
        'user_id' => 5,
        'title' => 'From Array',
        'model' => 'claude-3',
        'visibility' => 'public',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $chat = Chat::fromArray($data);

    expect($chat->id)->toBe('test-id-123')
        ->and($chat->userId)->toBe(5)
        ->and($chat->title)->toBe('From Array')
        ->and($chat->model)->toBe('claude-3')
        ->and($chat->isPublic())->toBeTrue()
    ;
});
