<?php

declare(strict_types=1);

use App\Application\DocumentCommands;
use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Document;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use Tests\Support\RecordingBus;

beforeEach(function (): void {
    $pdo = createTestPdo();
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("INSERT INTO users (email, password_hash, is_guest, created_at) VALUES ('a@example.com', 'hash', 0, datetime('now')), ('b@example.com', 'hash', 0, datetime('now'))");

    $this->chats = new SqliteChatRepository($pdo);
    $this->documents = new SqliteDocumentRepository($pdo);
    $this->bus = new RecordingBus();
    $this->commands = new DocumentCommands($this->documents, $this->chats, $this->bus);

    $this->chat = Chat::create(userId: 1);
    $this->publicChat = Chat::create(userId: 1, visibility: 'public');
    $this->otherChat = Chat::create(userId: 1);
    foreach ([$this->chat, $this->publicChat, $this->otherChat] as $chat) {
        $this->chats->save($chat);
    }

    $this->document = Document::code($this->chat->id, 'Script', 'print(1)');
    $this->documents->save($this->document);
    $this->documents->save($this->document->updateContent('print(2)'));
    $this->publicDocument = Document::text($this->publicChat->id, 'Notes', 'Hello');
    $this->documents->save($this->publicDocument);
});

describe('find', function (): void {
    it('returns the latest version to the owner, or the version asked for', function (): void {
        expect($this->commands->find(1, $this->chat->id, $this->document->id)->content)->toBe('print(2)')
            ->and($this->commands->find(1, $this->chat->id, $this->document->id, 1)->content)->toBe('print(1)')
            ->and($this->commands->find(1, $this->chat->id, $this->document->id, 1)->currentVersion)->toBe(1)
        ;
    });

    it('falls back to the latest version for a version the document does not have', function (): void {
        $document = $this->commands->find(1, $this->chat->id, $this->document->id, 7);

        expect($document->content)->toBe('print(2)')->and($document->currentVersion)->toBe(2);
    });

    it('lets anyone read the documents of a public chat, nobody else those of a private one', function (): void {
        expect($this->commands->find(2, $this->publicChat->id, $this->publicDocument->id)?->content)->toBe('Hello')
            ->and($this->commands->find(2, $this->chat->id, $this->document->id))->toBeNull()
            ->and($this->commands->versions(2, $this->chat->id, $this->document->id))->toBe([])
        ;
    });

    it('only finds a document through its own chat', function (): void {
        expect($this->commands->find(1, $this->otherChat->id, $this->document->id))->toBeNull()
            ->and($this->commands->find(2, $this->publicChat->id, $this->document->id))->toBeNull()
            ->and($this->commands->find(1, $this->chat->id, 'missing'))->toBeNull()
        ;
    });

    it('lists the versions newest first', function (): void {
        expect(array_column($this->commands->versions(1, $this->chat->id, $this->document->id), 'version'))->toBe([2, 1]);
    });
});

describe('update', function (): void {
    it('stores the content as a new version and announces it', function (): void {
        $status = $this->commands->update(1, $this->chat->id, $this->document->id, 'print(3)');

        $event = $this->bus->events()[0];
        expect($status)->toBe(204)
            ->and($this->documents->findWithContent($this->document->id)->content)->toBe('print(3)')
            ->and($event)->toBeInstanceOf(DocumentUpdatedEvent::class)
            ->and($event->action)->toBe('updated')
            ->and($event->chatId)->toBe($this->chat->id)
            ->and($event->version)->toBe(3)
        ;
    });

    it('refuses anyone but the owner, also on a public chat', function (): void {
        expect($this->commands->update(2, $this->publicChat->id, $this->publicDocument->id, 'Defaced'))->toBe(403)
            ->and($this->commands->update(2, $this->chat->id, $this->document->id, 'Defaced'))->toBe(403)
            ->and($this->documents->findWithContent($this->publicDocument->id)->content)->toBe('Hello')
            ->and($this->bus->emitted)->toBe([])
        ;
    });

    it('refuses a document of another chat', function (): void {
        expect($this->commands->update(1, $this->otherChat->id, $this->document->id, 'Moved'))->toBe(404)
            ->and($this->documents->findWithContent($this->document->id)->content)->toBe('print(2)')
        ;
    });
});

describe('delete', function (): void {
    it('deletes the document with its versions and announces it', function (): void {
        $status = $this->commands->delete(1, $this->chat->id, $this->document->id);

        $event = $this->bus->events()[0];
        expect($status)->toBe(204)
            ->and($this->documents->find($this->document->id))->toBeNull()
            ->and($this->documents->getVersions($this->document->id))->toBe([])
            ->and($event->action)->toBe('deleted')
            ->and($event->documentId)->toBe($this->document->id)
        ;
    });

    it('refuses anyone but the owner and documents of another chat', function (): void {
        expect($this->commands->delete(2, $this->publicChat->id, $this->publicDocument->id))->toBe(403)
            ->and($this->commands->delete(1, $this->otherChat->id, $this->document->id))->toBe(404)
            ->and($this->documents->find($this->publicDocument->id))->not->toBeNull()
            ->and($this->documents->find($this->document->id))->not->toBeNull()
            ->and($this->bus->emitted)->toBe([])
        ;
    });
});
