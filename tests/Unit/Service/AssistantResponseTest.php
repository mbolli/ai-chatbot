<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Service\AssistantResponse;
use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;

it('stores no parts for a plain text response', function (): void {
    $response = new AssistantResponse();
    $response->addText('Hello ');
    $response->addText('world');

    expect($response->content())->toBe('Hello world')
        ->and($response->parts())->toBeNull()
    ;
});

it('keeps text and tool parts in stream order', function (): void {
    $response = new AssistantResponse();
    $response->addText('Let me ');
    $response->addText('write that.');
    $response->addToolCall(new ToolCall('t1', 'createDocument', ['kind' => 'code', 'title' => 'App', 'content' => str_repeat('x', 2000)]));
    $response->addToolResult(new ToolResult('t1', 'createDocument', 'Document created successfully with ID: d1'));
    $response->addText('Done.');

    $parts = $response->parts();

    expect(array_column($parts, 'type'))->toBe(['text', 'tool_call', 'tool_result', 'text'])
        ->and($parts[0]['text'])->toBe('Let me write that.')
        ->and($parts[1]['input']['content'])->toBe(str_repeat('x', 500) . '… [truncated]')
        ->and($parts[1]['input']['title'])->toBe('App')
        ->and($parts[2])->toMatchArray(['id' => 't1', 'is_error' => false])
        ->and($response->toolInput('t1')['content'])->toHaveLength(2000)
        ->and($response->toolCallCount())->toBe(1)
        ->and($response->hasSuccessfulToolResult())->toBeTrue()
        ->and($response->text())->toBe('Let me write that.Done.')
    ;
});

it('does not count a failed tool call as a result', function (): void {
    $response = new AssistantResponse();
    $response->addToolCall(new ToolCall('t1', 'updateDocument', ['documentId' => 'x']));
    $response->addToolResult(new ToolResult('t1', 'updateDocument', 'Error: not found', true));

    expect($response->hasSuccessfulToolResult())->toBeFalse();
});

it('shows notices after the text', function (): void {
    $response = new AssistantResponse();
    $response->addText('Partial');
    $response->addNotice(StopReason::MaxTokens->value, AssistantResponse::noticeFor(StopReason::MaxTokens));

    expect($response->content())->toBe("Partial\n\n⚠️ *Response cut off at the length limit.*")
        ->and($response->text())->toBe('Partial')
    ;
});

it('has notices only for abnormal stop reasons', function (): void {
    expect(AssistantResponse::noticeFor(StopReason::EndTurn))->toBeNull()
        ->and(AssistantResponse::noticeFor(StopReason::StopSequence))->toBeNull()
        ->and(AssistantResponse::noticeFor(StopReason::Unknown))->toBeNull()
        ->and(AssistantResponse::noticeFor(StopReason::Refusal))->not->toBeNull()
        ->and(AssistantResponse::noticeFor(StopReason::ToolLimit))->not->toBeNull()
    ;
});
