<?php

declare(strict_types=1);

use App\Domain\Model\Chat;
use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use App\Infrastructure\Persistence\SqliteSuggestionRepository;

beforeEach(function (): void {
    $this->pdo = createTestPdo();
    $this->pdo->exec("INSERT INTO users (email, password_hash, is_guest, created_at) VALUES ('test@example.com', 'hash', 0, datetime('now'))");

    $chat = Chat::create(userId: 1, title: 'Test Chat');
    new SqliteChatRepository($this->pdo)->save($chat);

    $this->document = Document::text($chat->id, 'Essay', 'Their is a error here.');
    new SqliteDocumentRepository($this->pdo)->save($this->document);

    $this->repository = new SqliteSuggestionRepository($this->pdo);
});

it('round-trips a suggestion through the JSON content column', function (): void {
    $suggestion = Suggestion::create($this->document->id, 'Their is a error here.', 'There is an error here.', 'Fix "their" & article');

    $this->repository->save($suggestion);
    $found = $this->repository->find($suggestion->id);

    expect($found)->not->toBeNull()
        ->and($found->originalText)->toBe('Their is a error here.')
        ->and($found->suggestedText)->toBe('There is an error here.')
        ->and($found->description)->toBe('Fix "their" & article')
        ->and($found->isPending())->toBeTrue()
    ;
});

it('returns null for an unknown suggestion', function (): void {
    expect($this->repository->find('missing'))->toBeNull();
});

it('updates the status on save', function (): void {
    $suggestion = Suggestion::create($this->document->id, 'a', 'b', 'c');
    $this->repository->save($suggestion);

    $this->repository->save($suggestion->accept());

    expect($this->repository->find($suggestion->id)->status)->toBe(Suggestion::STATUS_ACCEPTED)
        ->and($this->repository->findPendingByDocument($this->document->id))->toBe([])
    ;
});

it('replaces only pending suggestions of the document', function (): void {
    $old = Suggestion::create($this->document->id, 'old', 'older', 'stale');
    $accepted = Suggestion::create($this->document->id, 'kept', 'kept!', 'history')->accept();
    $this->repository->save($old);
    $this->repository->save($accepted);

    $first = Suggestion::create($this->document->id, 'one', 'One', 'first');
    $second = Suggestion::create($this->document->id, 'two', 'Two', 'second');
    $this->repository->replacePending($this->document->id, [$first, $second]);

    $pending = $this->repository->findPendingByDocument($this->document->id);

    expect(array_map(fn (Suggestion $s): string => $s->id, $pending))->toBe([$first->id, $second->id])
        ->and($this->repository->find($old->id))->toBeNull()
        ->and($this->repository->find($accepted->id))->not->toBeNull()
    ;
});

it('is deleted together with its document', function (): void {
    $suggestion = Suggestion::create($this->document->id, 'a', 'b', 'c');
    $this->repository->save($suggestion);

    new SqliteDocumentRepository($this->pdo)->delete($this->document->id);

    expect($this->repository->find($suggestion->id))->toBeNull();
});
