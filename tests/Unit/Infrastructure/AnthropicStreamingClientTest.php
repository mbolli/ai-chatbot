<?php

declare(strict_types=1);

use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\StreamEnd;
use App\Domain\Service\Stream\TextDelta;
use App\Domain\Service\Stream\ThinkingDelta;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;
use App\Domain\Service\Stream\Usage;
use App\Infrastructure\AI\AnthropicStreamingClient;
use App\Infrastructure\AI\RetryPolicy;
use App\Infrastructure\AI\TransportException;
use Tests\Unit\Infrastructure\FakeSseTransport;

/**
 * @param array<string, int>         $usage
 * @param list<array<string, mixed>> $blockEvents
 *
 * @return list<array<string, mixed>>
 */
function anthropicMessage(array $blockEvents, string $stopReason, array $usage = [], int $outputTokens = 5): array {
    return [
        ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'usage' => $usage + ['input_tokens' => 10, 'output_tokens' => 1]]],
        ...$blockEvents,
        ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => $outputTokens]],
        ['type' => 'message_stop'],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function anthropicText(int $index, string ...$parts): array {
    return [
        ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']],
        ...array_map(static fn (string $p): array => ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $p]], $parts),
        ['type' => 'content_block_stop', 'index' => $index],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function anthropicThinking(int $index, string $summary, string $signature): array {
    return [
        ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
        ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'thinking_delta', 'thinking' => $summary]],
        ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'signature_delta', 'signature' => $signature]],
        ['type' => 'content_block_stop', 'index' => $index],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function anthropicToolUse(int $index, string $id, string $inputJson): array {
    return [
        ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => 'echo', 'input' => new stdClass()]],
        ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => substr($inputJson, 0, 5)]],
        ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => substr($inputJson, 5)]],
        ['type' => 'content_block_stop', 'index' => $index],
    ];
}

/**
 * @param list<float> $sleeps
 */
function anthropicClient(FakeSseTransport $transport, array &$sleeps = [], int $maxSteps = 5): AnthropicStreamingClient {
    $client = new AnthropicStreamingClient('key', 1024, $maxSteps, $transport, new RetryPolicy(sleep: function (float $s) use (&$sleeps): void {
        $sleeps[] = $s;
    }));
    $client->setTools([FakeSseTransport::echoTool()]);

    return $client;
}

it('streams text and ends with the stop reason and usage, whatever the read boundaries', function (int $readSize): void {
    $sse = FakeSseTransport::anthropic(anthropicMessage(
        anthropicText(0, 'Hel', 'lo'),
        'end_turn',
        ['input_tokens' => 12, 'cache_read_input_tokens' => 300, 'cache_creation_input_tokens' => 40],
        outputTokens: 7,
    ));
    $client = anthropicClient(new FakeSseTransport([$sse], $readSize));

    $events = FakeSseTransport::collect($client->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'claude-haiku-4-5'));

    expect($events)->toEqual([
        new TextDelta('Hel'),
        new TextDelta('lo'),
        new StreamEnd(StopReason::EndTurn, new Usage(12, 7, 300, 40)),
    ]);
})->with([1, 3, 64, 4096]);

it('streams thinking summaries, echoes the signed thinking block and sums usage across the tool continuation', function (): void {
    $transport = new FakeSseTransport([
        FakeSseTransport::anthropic(anthropicMessage(
            [...anthropicThinking(0, 'Planning the echo.', 'sig-abc'), ...anthropicToolUse(1, 'toolu_1', '{"text":"ping"}')],
            'tool_use',
            ['input_tokens' => 100, 'cache_creation_input_tokens' => 900],
            outputTokens: 30,
        )),
        FakeSseTransport::anthropic(anthropicMessage(
            anthropicText(0, 'Done.'),
            'end_turn',
            ['input_tokens' => 20, 'cache_read_input_tokens' => 900],
            outputTokens: 4,
        )),
    ]);

    $events = FakeSseTransport::collect(anthropicClient($transport)->streamChatRealtime([['role' => 'user', 'content' => 'Echo ping']], 'claude-sonnet-5-5', 'System'));

    expect($events)->toEqual([
        new ThinkingDelta('Planning the echo.'),
        new ToolCall('toolu_1', 'echo', ['text' => 'ping']),
        new ToolResult('toolu_1', 'echo', 'echo: ping'),
        new TextDelta('Done.'),
        new StreamEnd(StopReason::EndTurn, new Usage(120, 34, 900, 900)),
    ]);

    $continuation = $transport->payloads()[1]['messages'];
    expect($continuation)->toHaveCount(3)
        ->and($continuation[1]['content'][0])->toBe(['type' => 'thinking', 'thinking' => 'Planning the echo.', 'signature' => 'sig-abc'])
        ->and($continuation[1]['content'][1])->toMatchArray(['type' => 'tool_use', 'id' => 'toolu_1', 'input' => ['text' => 'ping']])
        ->and($continuation[2]['content'])->toBe([['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'echo: ping', 'is_error' => false]])
    ;
});

it('stops with ToolLimit when the last allowed request still asks for tools', function (int $maxSteps): void {
    $toolTurn = FakeSseTransport::anthropic(anthropicMessage(anthropicToolUse(0, 'toolu_x', '{"text":"again"}'), 'tool_use'));
    $transport = new FakeSseTransport(array_fill(0, $maxSteps, $toolTurn));
    $client = anthropicClient($transport, maxSteps: $maxSteps);

    $events = FakeSseTransport::collect($client->streamChatRealtime([['role' => 'user', 'content' => 'Loop']], 'claude-haiku-4-5'));

    expect($transport->bodies)->toHaveCount($maxSteps)
        ->and(array_filter($events, static fn (object $e): bool => $e instanceof ToolResult))->toHaveCount($maxSteps - 1)
        ->and(end($events))->toEqual(new StreamEnd(StopReason::ToolLimit, new Usage(10 * $maxSteps, 5 * $maxSteps)))
    ;
})->with([5, 2]);

it('does not run a tool call cut off by max_tokens', function (): void {
    $transport = new FakeSseTransport([FakeSseTransport::anthropic(anthropicMessage(anthropicToolUse(0, 'toolu_1', '{"text":"pi'), 'max_tokens'))]);

    $events = FakeSseTransport::collect(anthropicClient($transport)->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'claude-haiku-4-5'));

    expect($events)->toEqual([new StreamEnd(StopReason::MaxTokens, new Usage(10, 5))]);
});

it('sends top-level cache_control, a stable prefix, and summarized thinking only to the 5.5 models', function (string $model, bool $thinks): void {
    $sse = FakeSseTransport::anthropic(anthropicMessage(anthropicText(0, 'ok'), 'end_turn'));
    $transport = new FakeSseTransport([$sse, $sse]);
    $client = anthropicClient($transport);

    FakeSseTransport::collect($client->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], $model, 'System'));
    FakeSseTransport::collect($client->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], $model, 'System'));

    $payload = $transport->payloads()[0];
    expect($transport->bodies[0])->toBe($transport->bodies[1])
        ->and($payload['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and(array_keys($payload))->toBe(array_values(array_filter(
            ['model', 'max_tokens', 'cache_control', 'tools', 'system', 'messages', $thinks ? 'thinking' : null, $thinks ? 'output_config' : null, 'stream'],
        )))
    ;

    if ($thinks) {
        expect($payload['thinking'])->toBe(['type' => 'adaptive', 'display' => 'summarized'])
            ->and($payload['output_config'])->toBe(['effort' => 'low'])
        ;
    }
})->with([
    ['claude-haiku-4-5', false],
    ['claude-sonnet-5-5', true],
    ['claude-opus-5-5', true],
]);

it('retries a failed request before anything was streamed', function (string|TransportException $failure): void {
    $sleeps = [];
    $transport = new FakeSseTransport([$failure, FakeSseTransport::anthropic(anthropicMessage(anthropicText(0, 'ok'), 'end_turn'))]);

    $events = FakeSseTransport::collect(anthropicClient($transport, $sleeps)->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'claude-haiku-4-5'));

    expect($events[0])->toEqual(new TextDelta('ok'))
        ->and($transport->bodies)->toHaveCount(2)
        ->and($transport->bodies[0])->toBe($transport->bodies[1])
        ->and($sleeps)->toHaveCount(1)
    ;
})->with([
    'connection failure' => [new TransportException('Failed to connect')],
    'HTTP 529' => [new TransportException('HTTP 529', 529, '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}')],
    'overloaded_error event' => [FakeSseTransport::anthropic([
        ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]],
        ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
    ])],
    'api_error event' => [FakeSseTransport::anthropic([['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Internal']]])],
    'stream cut before message end' => [FakeSseTransport::anthropic([['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]]])],
]);

it('never retries once text was streamed', function (): void {
    $sleeps = [];
    $transport = new FakeSseTransport([FakeSseTransport::anthropic([
        ...array_slice(anthropicMessage(anthropicText(0, 'partial'), 'end_turn'), 0, 3),
        ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
    ])]);

    $events = [];
    $stream = anthropicClient($transport, $sleeps)->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'claude-haiku-4-5');

    expect(function () use ($stream, &$events): void {
        foreach ($stream as $event) {
            $events[] = $event;
        }
    })->toThrow(RuntimeException::class, 'overloaded');

    expect($events)->toEqual([new TextDelta('partial')])
        ->and($transport->bodies)->toHaveCount(1)
        ->and($sleeps)->toBe([])
    ;
});

it('throws non-retryable errors immediately with a descriptive message', function (int $status, string $message): void {
    $sleeps = [];
    $transport = new FakeSseTransport([new TransportException("HTTP {$status}", $status, '{"type":"error","error":{"type":"x","message":"Nope"}}')]);

    expect(function () use ($transport, &$sleeps): void {
        FakeSseTransport::collect(anthropicClient($transport, $sleeps)->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'claude-haiku-4-5'));
    })
        ->toThrow(RuntimeException::class, $message)
    ;
    expect($transport->bodies)->toHaveCount(1)->and($sleeps)->toBe([]);
})->with([
    [400, 'API error (HTTP 400): Nope'],
    [401, 'invalid_api_key: Nope'],
    [403, 'API error (HTTP 403): Nope'],
    [404, 'API error (HTTP 404): Nope'],
]);

it('gives up after three attempts and honors retry-after', function (): void {
    $sleeps = [];
    $transport = new FakeSseTransport([
        new TransportException('HTTP 429', 429, '{"error":{"message":"Slow down"}}', 2.5),
        new TransportException('HTTP 503', 503),
        new TransportException('HTTP 429', 429, '{"error":{"message":"Slow down"}}'),
    ]);

    expect(function () use ($transport, &$sleeps): void {
        FakeSseTransport::collect(anthropicClient($transport, $sleeps)->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'claude-haiku-4-5'));
    })
        ->toThrow(RuntimeException::class, 'rate limit exceeded: Slow down')
    ;
    expect($transport->bodies)->toHaveCount(3)
        ->and($sleeps)->toHaveCount(2)
        ->and($sleeps[0])->toBe(2.5)
    ;
});
