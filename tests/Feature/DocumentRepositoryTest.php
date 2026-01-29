<?php

declare(strict_types=1);

use App\Domain\Model\Chat;
use App\Domain\Model\Document;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;

beforeEach(function (): void {
    $this->pdo = createTestPdo();
    $this->documentRepository = new SqliteDocumentRepository($this->pdo);
    $this->chatRepository = new SqliteChatRepository($this->pdo);

    // Create test user
    $this->pdo->exec("INSERT INTO users (email, password_hash, is_guest, created_at) VALUES ('test@example.com', 'hash', 0, datetime('now'))");

    // Create test chat
    $chat = Chat::create(userId: 1, title: 'Test Chat');
    $this->chatRepository->save($chat);
    $this->chatId = $chat->id;
});

it('saves and finds a document', function (): void {
    $doc = Document::text($this->chatId, 'Test Doc', 'Hello world');

    $this->documentRepository->save($doc);

    $found = $this->documentRepository->find($doc->id);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($doc->id)
        ->and($found->title)->toBe('Test Doc')
        ->and($found->kind)->toBe('text')
    ;
});

it('returns null for non-existent document', function (): void {
    $found = $this->documentRepository->find('non-existent-id');

    expect($found)->toBeNull();
});

it('finds documents by chat', function (): void {
    $doc1 = Document::text($this->chatId, 'Doc 1', 'Content 1');
    $doc2 = Document::code($this->chatId, 'Doc 2', 'code', 'python');

    $this->documentRepository->save($doc1);
    $this->documentRepository->save($doc2);

    $docs = $this->documentRepository->findByChat($this->chatId);

    expect($docs)->toHaveCount(2);
});

it('saves document with content and creates version', function (): void {
    $doc = Document::text($this->chatId, 'Versioned Doc', 'Initial content');

    $this->documentRepository->save($doc);

    $found = $this->documentRepository->findWithContent($doc->id);

    expect($found->content)->toBe('Initial content')
        ->and($found->currentVersion)->toBe(1)
    ;
});

it('updates document and creates new version', function (): void {
    $doc = Document::text($this->chatId, 'Doc', 'v1');
    $this->documentRepository->save($doc);

    $updated = $doc->updateContent('v2');
    $this->documentRepository->save($updated);

    $found = $this->documentRepository->findWithContent($doc->id);

    expect($found->content)->toBe('v2')
        ->and($found->currentVersion)->toBe(2)
    ;
});

it('retrieves specific version', function (): void {
    $doc = Document::text($this->chatId, 'Doc', 'version 1');
    $this->documentRepository->save($doc);

    $updated = $doc->updateContent('version 2');
    $this->documentRepository->save($updated);

    $v1 = $this->documentRepository->findWithContent($doc->id, 1);
    $v2 = $this->documentRepository->findWithContent($doc->id, 2);

    expect($v1->content)->toBe('version 1')
        ->and($v2->content)->toBe('version 2')
    ;
});

it('gets version history', function (): void {
    $doc = Document::text($this->chatId, 'Doc', 'v1');
    $this->documentRepository->save($doc);

    $updated = $doc->updateContent('v2');
    $this->documentRepository->save($updated);

    $versions = $this->documentRepository->getVersions($doc->id);

    expect($versions)->toHaveCount(2)
        ->and($versions[0]['version'])->toBe(2)
        ->and($versions[1]['version'])->toBe(1)
    ;
});

it('deletes a document', function (): void {
    $doc = Document::text($this->chatId, 'To Delete', 'bye');
    $this->documentRepository->save($doc);

    $this->documentRepository->delete($doc->id);

    $found = $this->documentRepository->find($doc->id);
    expect($found)->toBeNull();
});

it('deletes documents by chat', function (): void {
    $doc1 = Document::text($this->chatId, 'Doc 1', 'Content');
    $doc2 = Document::text($this->chatId, 'Doc 2', 'Content');

    $this->documentRepository->save($doc1);
    $this->documentRepository->save($doc2);

    $this->documentRepository->deleteByChat($this->chatId);

    $docs = $this->documentRepository->findByChat($this->chatId);
    expect($docs)->toBeEmpty();
});

it('stores code document with language', function (): void {
    $doc = Document::code($this->chatId, 'script.py', 'print("hi")', 'python');

    $this->documentRepository->save($doc);

    $found = $this->documentRepository->find($doc->id);

    expect($found->kind)->toBe('code')
        ->and($found->language)->toBe('python')
    ;
});
