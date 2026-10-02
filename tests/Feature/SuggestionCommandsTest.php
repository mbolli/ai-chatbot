<?php

declare(strict_types=1);

use App\Application\SuggestionCommands;
use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\SuggestionsUpdatedEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use App\Infrastructure\Persistence\SqliteSuggestionRepository;
use Tests\Support\RecordingBus;

beforeEach(function (): void {
    $pdo = createTestPdo();
    $pdo->exec("INSERT INTO users (email, password_hash, is_guest, created_at) VALUES ('a@example.com', 'hash', 0, datetime('now')), ('b@example.com', 'hash', 0, datetime('now'))");

    $this->chats = new SqliteChatRepository($pdo);
    $this->documents = new SqliteDocumentRepository($pdo);
    $this->suggestions = new SqliteSuggestionRepository($pdo);
    $this->bus = new RecordingBus();
    $this->commands = new SuggestionCommands($this->suggestions, $this->documents, $this->chats, $this->bus);

    $this->chat = Chat::create(userId: 1, visibility: 'public');
    $this->chats->save($this->chat);
    $this->document = Document::text($this->chat->id, 'Essay', 'Their is a error. Their is a error.');
    $this->documents->save($this->document);

    $this->suggestion = Suggestion::create($this->document->id, 'Their is a error.', 'There is an error.', 'Grammar');
    $this->suggestions->save($this->suggestion);
});

it('accepts a suggestion as a new document version replacing the first occurrence', function (): void {
    $status = $this->commands->accept(1, $this->chat->id, $this->suggestion->id);

    $document = $this->documents->findWithContent($this->document->id);
    [$userId, $event] = $this->bus->emitted[0];

    expect($status)->toBe(204)
        ->and($document->content)->toBe('There is an error. Their is a error.')
        ->and($document->currentVersion)->toBe(2)
        ->and($this->suggestions->find($this->suggestion->id)->status)->toBe(Suggestion::STATUS_ACCEPTED)
        ->and($userId)->toBe(1)
        ->and($event)->toBeInstanceOf(DocumentUpdatedEvent::class)
        ->and($event->action)->toBe('updated')
        ->and($event->version)->toBe(2)
    ;
});

it('dismisses a suggestion without touching the document', function (): void {
    $status = $this->commands->dismiss(1, $this->chat->id, $this->suggestion->id);

    $event = $this->bus->events()[0];

    expect($status)->toBe(204)
        ->and($this->documents->findWithContent($this->document->id)->currentVersion)->toBe(1)
        ->and($this->suggestions->find($this->suggestion->id)->status)->toBe(Suggestion::STATUS_REJECTED)
        ->and($event)->toBeInstanceOf(SuggestionsUpdatedEvent::class)
        ->and($event->action)->toBe(SuggestionsUpdatedEvent::ACTION_DISMISSED)
    ;
});

it('rejects a suggestion whose sentence is gone instead of applying it', function (): void {
    $this->documents->save($this->document->updateContent('Rewritten by hand.'));

    $status = $this->commands->accept(1, $this->chat->id, $this->suggestion->id);

    expect($status)->toBe(204)
        ->and($this->documents->findWithContent($this->document->id)->content)->toBe('Rewritten by hand.')
        ->and($this->suggestions->find($this->suggestion->id)->status)->toBe(Suggestion::STATUS_REJECTED)
        ->and($this->bus->events()[0])->toBeInstanceOf(SuggestionsUpdatedEvent::class)
    ;
});

it('forbids anyone but the owner, also on a public chat', function (string $action): void {
    $status = $this->commands->{$action}(2, $this->chat->id, $this->suggestion->id);

    expect($status)->toBe(403)
        ->and($this->suggestions->find($this->suggestion->id)->isPending())->toBeTrue()
        ->and($this->bus->emitted)->toBe([])
    ;
})->with(['accept', 'dismiss']);

it('only resolves a suggestion through the chat of its document', function (string $action): void {
    $otherChat = Chat::create(userId: 1);
    $this->chats->save($otherChat);

    expect($this->commands->{$action}(1, $otherChat->id, $this->suggestion->id))->toBe(404)
        ->and($this->suggestions->find($this->suggestion->id)->isPending())->toBeTrue()
    ;
})->with(['accept', 'dismiss']);

it('returns 404 for an unknown suggestion', function (): void {
    expect($this->commands->accept(1, $this->chat->id, '00000000-0000-0000-0000-000000000000'))->toBe(404);
});

it('ignores a second accept of the same suggestion', function (): void {
    $this->commands->accept(1, $this->chat->id, $this->suggestion->id);
    $this->commands->accept(1, $this->chat->id, $this->suggestion->id);

    expect($this->documents->findWithContent($this->document->id)->currentVersion)->toBe(2)
        ->and($this->bus->emitted)->toHaveCount(1)
    ;
});

describe('pending', function (): void {
    it('lists the suggestions that still apply, to the owner only', function (): void {
        $stale = Suggestion::create($this->document->id, 'Deleted sentence.', 'Gone.', 'Stale');
        $this->suggestions->save($stale);
        $document = $this->documents->findWithContent($this->document->id);

        expect(array_map(fn (Suggestion $s): string => $s->id, $this->commands->pending(1, $this->chat->id, $document)))->toBe([$this->suggestion->id])
            ->and($this->commands->pending(2, $this->chat->id, $document))->toBe([])
        ;
    });

    it('lists none for a document of another chat or another kind', function (): void {
        $otherChat = Chat::create(userId: 1);
        $this->chats->save($otherChat);
        $sheet = Document::sheet($this->chat->id, 'Data', 'Their is a error.');

        expect($this->commands->pending(1, $otherChat->id, $this->documents->findWithContent($this->document->id)))->toBe([])
            ->and($this->commands->pending(1, $this->chat->id, $sheet))->toBe([])
        ;
    });
});
