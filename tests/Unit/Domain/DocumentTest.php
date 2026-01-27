<?php

declare(strict_types=1);

use App\Domain\Model\Document;

it('creates a text document', function (): void {
    $doc = Document::text('chat-123', 'My Document', 'Hello world');

    expect($doc->id)->toBeUuid()
        ->and($doc->chatId)->toBe('chat-123')
        ->and($doc->kind)->toBe('text')
        ->and($doc->title)->toBe('My Document')
        ->and($doc->content)->toBe('Hello world')
        ->and($doc->isText())->toBeTrue()
    ;
});

it('creates a code document', function (): void {
    $doc = Document::code('chat-123', 'script.py', 'print("hello")', 'python');

    expect($doc->kind)->toBe('code')
        ->and($doc->language)->toBe('python')
        ->and($doc->isCode())->toBeTrue()
    ;
});

it('creates a sheet document', function (): void {
    $doc = Document::sheet('chat-123', 'Data', 'col1,col2\na,b');

    expect($doc->kind)->toBe('sheet')
        ->and($doc->isSheet())->toBeTrue()
    ;
});

it('creates an image document', function (): void {
    $doc = Document::image('chat-123', 'Image', 'data:image/png;base64,...');

    expect($doc->kind)->toBe('image')
        ->and($doc->isImage())->toBeTrue()
    ;
});

it('updates content and increments version', function (): void {
    $doc = Document::text('chat-123', 'Doc', 'v1');
    $updated = $doc->updateContent('v2');

    expect($updated->content)->toBe('v2')
        ->and($updated->currentVersion)->toBe(2)
        ->and($updated->id)->toBe($doc->id)
    ;
});

it('updates title', function (): void {
    $doc = Document::text('chat-123', 'Old Title', '');
    $updated = $doc->updateTitle('New Title');

    expect($updated->title)->toBe('New Title')
        ->and($updated->currentVersion)->toBe($doc->currentVersion)
    ;
});

it('serializes to array', function (): void {
    $doc = Document::code('chat-123', 'main.py', 'code', 'python');
    $array = $doc->toArray();

    expect($array)->toHaveKeys(['id', 'chatId', 'kind', 'title', 'language', 'content', 'currentVersion']);
});

it('deserializes from array', function (): void {
    $now = time();
    $data = [
        'id' => 'doc-123',
        'chat_id' => 'chat-456',
        'message_id' => 'msg-789',
        'kind' => 'code',
        'title' => 'script.js',
        'language' => 'javascript',
        'content' => 'console.log("hi")',
        'version' => 3,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $doc = Document::fromArray($data);

    expect($doc->id)->toBe('doc-123')
        ->and($doc->messageId)->toBe('msg-789')
        ->and($doc->language)->toBe('javascript')
        ->and($doc->currentVersion)->toBe(3);
});
