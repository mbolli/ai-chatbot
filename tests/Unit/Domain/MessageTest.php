<?php

declare(strict_types=1);

use App\Domain\Model\Message;

it('creates a user message', function (): void {
    $message = Message::user('chat-123', 'Hello world');

    expect($message->id)->toBeUuid()
        ->and($message->chatId)->toBe('chat-123')
        ->and($message->role)->toBe('user')
        ->and($message->content)->toBe('Hello world')
        ->and($message->isUser())->toBeTrue()
        ->and($message->isAssistant())->toBeFalse()
    ;
});

it('creates an assistant message', function (): void {
    $message = Message::assistant('chat-123', 'Hello!');

    expect($message->role)->toBe('assistant')
        ->and($message->content)->toBe('Hello!')
        ->and($message->isAssistant())->toBeTrue()
    ;
});

it('creates a system message', function (): void {
    $message = Message::system('chat-123', 'You are a helpful assistant.');

    expect($message->role)->toBe('system')
        ->and($message->content)->toBe('You are a helpful assistant.')
    ;
});

it('appends content', function (): void {
    $message = Message::assistant('chat-123', 'Hello');
    $updated = $message->appendContent(' world!');

    expect($updated->content)->toBe('Hello world!')
        ->and($updated->id)->toBe($message->id)
    ;
});

it('handles message parts', function (): void {
    $parts = [
        ['type' => 'text', 'text' => 'Here is your code:'],
        ['type' => 'tool-invocation', 'toolName' => 'createDocument', 'args' => ['title' => 'Code']],
    ];

    $message = Message::assistant('chat-123', '', $parts);

    expect($message->parts)->toHaveCount(2)
        ->and($message->hasToolCalls())->toBeTrue()
    ;
});

it('extracts tool calls', function (): void {
    $parts = [
        ['type' => 'text', 'text' => 'Creating document...'],
        ['type' => 'tool-invocation', 'toolName' => 'createDocument', 'args' => []],
        ['type' => 'tool-invocation', 'toolName' => 'updateDocument', 'args' => []],
    ];

    $message = Message::assistant('chat-123', '', $parts);
    $toolCalls = $message->getToolCalls();

    expect($toolCalls)->toHaveCount(2);
});

it('adds parts', function (): void {
    $message = Message::assistant('chat-123');
    $updated = $message->addPart(['type' => 'text', 'text' => 'Hello']);

    expect($updated->parts)->toHaveCount(1)
        ->and($updated->parts[0]['type'])->toBe('text')
    ;
});

it('serializes to array', function (): void {
    $message = Message::user('chat-123', 'Test');
    $array = $message->toArray();

    expect($array)->toHaveKeys(['id', 'chatId', 'role', 'content', 'parts', 'createdAt']);
});

it('deserializes from array', function (): void {
    $now = time();
    $data = [
        'id' => 'msg-123',
        'chat_id' => 'chat-456',
        'role' => 'assistant',
        'content' => 'Hello!',
        'parts' => json_encode([['type' => 'text', 'text' => 'Hello!']]),
        'created_at' => $now,
    ];

    $message = Message::fromArray($data);

    expect($message->id)->toBe('msg-123')
        ->and($message->chatId)->toBe('chat-456')
        ->and($message->parts)->toHaveCount(1)
    ;
});
