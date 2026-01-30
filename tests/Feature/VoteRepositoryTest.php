<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Model\Chat;
use App\Domain\Model\Message;
use App\Domain\Model\Vote;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteMessageRepository;
use App\Infrastructure\Persistence\SqliteVoteRepository;

beforeEach(function (): void {
    $this->pdo = new \PDO('sqlite::memory:');
    $this->pdo->exec(file_get_contents(__DIR__ . '/../../data/schema.sql'));

    // Create test user
    $this->pdo->exec("INSERT INTO users (id, email, is_guest) VALUES (1, 'test@example.com', 0)");

    $this->chatRepo = new SqliteChatRepository($this->pdo);
    $this->messageRepo = new SqliteMessageRepository($this->pdo);
    $this->voteRepo = new SqliteVoteRepository($this->pdo);
});

it('creates an upvote', function (): void {
    // Create chat and message
    $chat = Chat::create(1);
    $this->chatRepo->save($chat);

    $message = Message::assistant($chat->id);
    $this->messageRepo->save($message);

    // Create vote
    $vote = Vote::upvote($chat->id, $message->id, 1);
    $this->voteRepo->save($vote);

    // Verify
    $found = $this->voteRepo->find($message->id, 1);
    expect($found)->not->toBeNull();
    expect($found->isUpvote)->toBeTrue();
});

it('creates a downvote', function (): void {
    $chat = Chat::create(1);
    $this->chatRepo->save($chat);

    $message = Message::assistant($chat->id);
    $this->messageRepo->save($message);

    $vote = Vote::downvote($chat->id, $message->id, 1);
    $this->voteRepo->save($vote);

    $found = $this->voteRepo->find($message->id, 1);
    expect($found)->not->toBeNull();
    expect($found->isUpvote)->toBeFalse();
});

it('updates vote from upvote to downvote', function (): void {
    $chat = Chat::create(1);
    $this->chatRepo->save($chat);

    $message = Message::assistant($chat->id);
    $this->messageRepo->save($message);

    // Create upvote
    $vote = Vote::upvote($chat->id, $message->id, 1);
    $this->voteRepo->save($vote);

    // Update to downvote
    $vote2 = Vote::downvote($chat->id, $message->id, 1);
    $this->voteRepo->save($vote2);

    // Verify only one vote exists and it's a downvote
    $found = $this->voteRepo->find($message->id, 1);
    expect($found)->not->toBeNull();
    expect($found->isUpvote)->toBeFalse();

    $allVotes = $this->voteRepo->findByMessage($message->id);
    expect($allVotes)->toHaveCount(1);
});

it('deletes a vote', function (): void {
    $chat = Chat::create(1);
    $this->chatRepo->save($chat);

    $message = Message::assistant($chat->id);
    $this->messageRepo->save($message);

    $vote = Vote::upvote($chat->id, $message->id, 1);
    $this->voteRepo->save($vote);

    // Delete
    $this->voteRepo->delete($message->id, 1);

    $found = $this->voteRepo->find($message->id, 1);
    expect($found)->toBeNull();
});

it('finds votes by chat', function (): void {
    $chat = Chat::create(1);
    $this->chatRepo->save($chat);

    $message1 = Message::assistant($chat->id);
    $this->messageRepo->save($message1);

    $message2 = Message::assistant($chat->id);
    $this->messageRepo->save($message2);

    $this->voteRepo->save(Vote::upvote($chat->id, $message1->id, 1));
    $this->voteRepo->save(Vote::downvote($chat->id, $message2->id, 1));

    $votes = $this->voteRepo->findByChat($chat->id);
    expect($votes)->toHaveCount(2);
});

it('deletes all votes by chat', function (): void {
    $chat = Chat::create(1);
    $this->chatRepo->save($chat);

    $message1 = Message::assistant($chat->id);
    $this->messageRepo->save($message1);

    $message2 = Message::assistant($chat->id);
    $this->messageRepo->save($message2);

    $this->voteRepo->save(Vote::upvote($chat->id, $message1->id, 1));
    $this->voteRepo->save(Vote::downvote($chat->id, $message2->id, 1));

    $this->voteRepo->deleteByChat($chat->id);

    $votes = $this->voteRepo->findByChat($chat->id);
    expect($votes)->toHaveCount(0);
});
