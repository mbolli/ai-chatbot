<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\VoteCommands;
use App\Domain\Event\VoteUpdatedEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Message;
use App\Infrastructure\EventBus\EventBusInterface;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteMessageRepository;
use App\Infrastructure\Persistence\SqliteVoteRepository;

beforeEach(function (): void {
    $this->pdo = createTestPdo();
    $this->pdo->exec("INSERT INTO users (id, email, is_guest) VALUES (1, 'owner@example.com', 0), (2, 'other@example.com', 0)");

    $chats = new SqliteChatRepository($this->pdo);
    $messages = new SqliteMessageRepository($this->pdo);
    $this->votes = new SqliteVoteRepository($this->pdo);

    $this->chat = Chat::create(userId: 1, model: 'claude-haiku-4-5');
    $chats->save($this->chat);
    $this->question = Message::user($this->chat->id, 'Hi');
    $this->answer = Message::assistant($this->chat->id, 'Hello');
    $messages->save($this->question);
    $messages->save($this->answer);

    $this->events = [];
    $bus = new class($this->events) implements EventBusInterface {
        /** @param list<object> $events */
        public function __construct(private array &$events) {}

        public function emit(int $userId, object $event): void {
            $this->events[] = $event;
        }
    };
    $this->commands = new VoteCommands($this->votes, $chats, $messages, $bus);
});

it('casts, switches and toggles off a vote', function (): void {
    $vote = fn (bool $up): int => $this->commands->vote(1, $this->chat->id, $this->answer->id, $up);

    expect($vote(true))->toBe(204)
        ->and($this->votes->findByChatAndUser($this->chat->id, 1))->toBe([$this->answer->id => true])
    ;
    expect($vote(false))->toBe(204)
        ->and($this->votes->findByChatAndUser($this->chat->id, 1))->toBe([$this->answer->id => false])
    ;
    expect($vote(false))->toBe(204)
        ->and($this->votes->findByChatAndUser($this->chat->id, 1))->toBe([])
    ;

    expect(array_map(fn (VoteUpdatedEvent $e): ?bool => $e->vote, $this->events))->toBe([true, false, null]);
});

it('refuses votes it must not record', function (int $userId, string $chatId, string $messageId, int $status): void {
    expect($this->commands->vote($userId, $chatId, $messageId, true))->toBe($status)
        ->and($this->votes->findByChat($this->chat->id))->toBe([])
        ->and($this->events)->toBe([])
    ;
})->with([
    'another user' => fn (): array => [2, $this->chat->id, $this->answer->id, 403],
    'a user message' => fn (): array => [1, $this->chat->id, $this->question->id, 400],
    'an unknown chat' => fn (): array => [1, 'missing', $this->answer->id, 404],
    'an unknown message' => fn (): array => [1, $this->chat->id, 'missing', 404],
]);

it('refuses a message from another chat', function (): void {
    $chats = new SqliteChatRepository($this->pdo);
    $otherChat = Chat::create(userId: 1, model: 'claude-haiku-4-5');
    $chats->save($otherChat);

    expect($this->commands->vote(1, $otherChat->id, $this->answer->id, true))->toBe(404);
});
