<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Model\Message;
use App\Domain\Service\AssistantResponse;
use App\Domain\Service\ConversationHistoryBuilder;
use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;

function chars(int $tokens, string $char = 'x'): string {
    return str_repeat($char, $tokens * 4);
}

it('estimates four characters per token, rounding up', function (): void {
    expect(ConversationHistoryBuilder::estimateTokens(''))->toBe(0)
        ->and(ConversationHistoryBuilder::estimateTokens('abcd'))->toBe(1)
        ->and(ConversationHistoryBuilder::estimateTokens('abcde'))->toBe(2)
        ->and(ConversationHistoryBuilder::estimateTokens('äöüß'))->toBe(1)
    ;
});

it('keeps everything that fits the budget', function (): void {
    $history = (new ConversationHistoryBuilder(100))->build([
        Message::user('c', chars(30)),
        Message::assistant('c', chars(30)),
        Message::user('c', chars(30)),
    ]);

    expect($history)->toHaveCount(3);
});

it('drops the oldest messages and truncates the oldest kept one', function (): void {
    $history = (new ConversationHistoryBuilder(100))->build([
        Message::user('c', chars(500, 'a')),
        Message::assistant('c', chars(500, 'b')),
        Message::user('c', chars(500, 'c')),
        Message::assistant('c', chars(40, 'd')),
        Message::user('c', chars(20, 'e')),
    ]);

    expect(array_column($history, 'role'))->toBe(['user', 'assistant', 'user'])
        ->and($history[0]['content'])->toBe(chars(40, 'c') . '… [truncated]')
        ->and($history[1]['content'])->toBe(chars(40, 'd'))
        ->and($history[2]['content'])->toBe(chars(20, 'e'))
    ;
});

it('skips a truncated remainder that would be too short to help', function (): void {
    $history = (new ConversationHistoryBuilder(100))->build([
        Message::user('c', chars(500)),
        Message::assistant('c', chars(40)),
        Message::user('c', chars(50)),
    ]);

    expect(array_column($history, 'role'))->toBe(['user']);
});

it('always keeps the newest message whole, even over budget', function (): void {
    $history = (new ConversationHistoryBuilder(10))->build([
        Message::user('c', 'old'),
        Message::assistant('c', 'older answer'),
        Message::user('c', chars(50)),
    ]);

    expect($history)->toBe([['role' => 'user', 'content' => chars(50)]]);
});

it('never starts the history with an assistant turn', function (): void {
    $history = (new ConversationHistoryBuilder(60))->build([
        Message::user('c', chars(100)),
        Message::assistant('c', chars(30)),
        Message::user('c', chars(20)),
    ]);

    expect(array_column($history, 'role'))->toBe(['user']);
});

it('skips empty messages such as the streaming placeholder', function (): void {
    $history = (new ConversationHistoryBuilder())->build([
        Message::user('c', 'Hi'),
        Message::assistant('c'),
    ]);

    expect($history)->toBe([['role' => 'user', 'content' => 'Hi']]);
});

it('replays tool calls compactly and leaves notices out', function (): void {
    $response = new AssistantResponse();
    $response->addText('Done.');
    $response->addToolCall(new ToolCall('t1', 'updateDocument', ['documentId' => 'doc-1', 'content' => str_repeat('z', 5000), 'title' => 'New']));
    $response->addToolResult(new ToolResult('t1', 'updateDocument', "Document 'Old' updated successfully. New version: 3"));
    $response->addToolCall(new ToolCall('t2', 'updateDocument', ['documentId' => 'nope', 'content' => 'x']));
    $response->addToolResult(new ToolResult('t2', 'updateDocument', "Error: Document with ID 'nope' not found.", true));
    $response->addNotice('max_tokens', AssistantResponse::noticeFor(StopReason::MaxTokens));

    $message = Message::assistant('c', $response->content(), $response->parts());

    expect((new ConversationHistoryBuilder())->contentFor($message))->toBe(
        "Done.\n\n"
        . "[updateDocument {\"documentId\":\"doc-1\",\"title\":\"New\"} → Document 'Old' updated successfully. New version: 3]\n"
        . "[updateDocument {\"documentId\":\"nope\",\"content\":\"x\"} → Error: Document with ID 'nope' not found.]",
    );
});

it('uses the stored content for messages without parts', function (): void {
    $message = Message::assistant('c', 'Legacy answer');

    expect((new ConversationHistoryBuilder())->contentFor($message))->toBe('Legacy answer');
});

it('drops an assistant message that only holds a notice', function (): void {
    $response = new AssistantResponse();
    $response->addNotice(AssistantResponse::NOTICE_ERROR, '⚠️ Something failed');

    $history = (new ConversationHistoryBuilder())->build([
        Message::user('c', 'Hi'),
        Message::assistant('c', $response->content(), $response->parts()),
        Message::user('c', 'Again'),
    ]);

    expect(array_column($history, 'content'))->toBe(['Hi', 'Again']);
});
