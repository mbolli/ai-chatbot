<?php

declare(strict_types=1);

use App\Domain\Model\Chat;
use App\Infrastructure\Persistence\SqliteChatRepository;

beforeEach(function (): void {
    $this->pdo = createTestPdo();
    $this->repository = new SqliteChatRepository($this->pdo);

    // Create test users
    $this->pdo->exec("INSERT INTO users (email, password, registered) VALUES ('test@example.com', 'hash', " . time() . ')');
    $this->pdo->exec("INSERT INTO users (email, password, registered) VALUES ('test2@example.com', 'hash', " . time() . ')');
});

it('saves and finds a chat', function (): void {
    $chat = Chat::create(userId: 1, title: 'Test Chat');

    $this->repository->save($chat);

    $found = $this->repository->find($chat->id);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($chat->id)
        ->and($found->title)->toBe('Test Chat')
        ->and($found->userId)->toBe(1)
    ;
});

it('returns null for non-existent chat', function (): void {
    $found = $this->repository->find('non-existent-id');

    expect($found)->toBeNull();
});

it('finds chats by user', function (): void {
    $chat1 = Chat::create(userId: 1, title: 'Chat 1');
    $chat2 = Chat::create(userId: 1, title: 'Chat 2');
    $chat3 = Chat::create(userId: 2, title: 'Other user');

    $this->repository->save($chat1);
    $this->repository->save($chat2);
    $this->repository->save($chat3);

    $userChats = $this->repository->findByUser(1);

    expect($userChats)->toHaveCount(2);
});

it('orders chats by updated_at desc', function (): void {
    $chat1 = Chat::create(userId: 1, title: 'First');
    $this->repository->save($chat1);

    sleep(1); // Ensure different timestamp

    $chat2 = Chat::create(userId: 1, title: 'Second');
    $this->repository->save($chat2);

    $chats = $this->repository->findByUser(1);

    expect($chats[0]->title)->toBe('Second')
        ->and($chats[1]->title)->toBe('First')
    ;
});

it('updates an existing chat', function (): void {
    $chat = Chat::create(userId: 1, title: 'Original');
    $this->repository->save($chat);

    $updated = $chat->updateTitle('Updated');
    $this->repository->save($updated);

    $found = $this->repository->find($chat->id);

    expect($found->title)->toBe('Updated');
});

it('deletes a chat', function (): void {
    $chat = Chat::create(userId: 1);
    $this->repository->save($chat);

    $this->repository->delete($chat->id);

    expect($this->repository->find($chat->id))->toBeNull();
});

it('deletes all chats for a user', function (): void {
    $chat1 = Chat::create(userId: 1);
    $chat2 = Chat::create(userId: 1);
    $this->repository->save($chat1);
    $this->repository->save($chat2);

    $this->repository->deleteByUser(1);

    expect($this->repository->findByUser(1))->toBeEmpty();
});

it('respects limit and offset', function (): void {
    for ($i = 1; $i <= 5; ++$i) {
        $chat = Chat::create(userId: 1, title: "Chat {$i}");
        $this->repository->save($chat);
    }

    $limited = $this->repository->findByUser(1, limit: 2, offset: 1);

    expect($limited)->toHaveCount(2);
});
