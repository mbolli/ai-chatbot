<?php

declare(strict_types=1);

use App\Domain\Model\Document;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Infrastructure\AI\Tools\CreateDocumentTool;
use App\Infrastructure\AI\Tools\UpdateDocumentTool;

describe('CreateDocumentTool', function (): void {
    it('creates a text document when invoked', function (): void {
        $repo = Mockery::mock(DocumentRepositoryInterface::class);
        $repo->shouldReceive('save')->once()->withArgs(fn (Document $doc): bool => $doc->kind === 'text' && $doc->title === 'Test Doc');

        $tool = new CreateDocumentTool($repo);
        $tool->setChatContext('chat-123', 'msg-456');

        $result = $tool->createDocument('text', 'Test Doc', 'Hello world');

        expect($result)->toContain('created successfully')
            ->and($tool->getLastCreatedDocument())->not->toBeNull()
            ->and($tool->getLastCreatedDocument()->kind)->toBe('text')
        ;
    });

    it('creates a code document with language', function (): void {
        $repo = Mockery::mock(DocumentRepositoryInterface::class);
        $repo->shouldReceive('save')->once();

        $tool = new CreateDocumentTool($repo);
        $tool->setChatContext('chat-123');

        $result = $tool->createDocument('code', 'script.py', 'print("hi")', 'python');
        $doc = $tool->getLastCreatedDocument();

        expect($doc->kind)->toBe('code')
            ->and($doc->language)->toBe('python')
        ;
    });

    it('returns error when chat context not set', function (): void {
        $repo = Mockery::mock(DocumentRepositoryInterface::class);
        $repo->shouldNotReceive('save');

        $tool = new CreateDocumentTool($repo);

        $result = $tool->createDocument('text', 'Test', 'Content');

        expect($result)->toContain('Error')
            ->and($tool->getLastCreatedDocument())->toBeNull()
        ;
    });

    it('returns error for invalid kind', function (): void {
        $repo = Mockery::mock(DocumentRepositoryInterface::class);
        $repo->shouldNotReceive('save');

        $tool = new CreateDocumentTool($repo);
        $tool->setChatContext('chat-123');

        $result = $tool->createDocument('invalid', 'Test', 'Content');

        expect($result)->toContain('Error')
            ->and($result)->toContain('Invalid document kind')
        ;
    });
});

describe('UpdateDocumentTool', function (): void {
    it('updates document content', function (): void {
        $existingDoc = Document::text('chat-123', 'Original', 'Old content');

        $repo = Mockery::mock(DocumentRepositoryInterface::class);
        $repo->shouldReceive('findWithContent')
            ->with($existingDoc->id)
            ->andReturn($existingDoc)
        ;
        $repo->shouldReceive('save')->once();

        $tool = new UpdateDocumentTool($repo);

        $result = $tool->updateDocument($existingDoc->id, 'New content');

        expect($result)->toContain('updated successfully')
            ->and($tool->getLastUpdatedDocument()->content)->toBe('New content')
        ;
    });

    it('updates document title and content', function (): void {
        $existingDoc = Document::text('chat-123', 'Original', 'Old');

        $repo = Mockery::mock(DocumentRepositoryInterface::class);
        $repo->shouldReceive('findWithContent')->andReturn($existingDoc);
        $repo->shouldReceive('save')->once();

        $tool = new UpdateDocumentTool($repo);

        $result = $tool->updateDocument($existingDoc->id, 'New content', 'New Title');
        $updated = $tool->getLastUpdatedDocument();

        expect($updated->content)->toBe('New content')
            ->and($updated->title)->toBe('New Title')
        ;
    });

    it('returns error for non-existent document', function (): void {
        $repo = Mockery::mock(DocumentRepositoryInterface::class);
        $repo->shouldReceive('findWithContent')
            ->with('non-existent')
            ->andReturnNull()
        ;
        $repo->shouldNotReceive('save');

        $tool = new UpdateDocumentTool($repo);

        $result = $tool->updateDocument('non-existent', 'Content');

        expect($result)->toContain('Error')
            ->and($result)->toContain('not found')
        ;
    });
});
