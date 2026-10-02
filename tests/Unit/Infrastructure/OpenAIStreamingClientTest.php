<?php

declare(strict_types=1);

use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\StreamEnd;
use App\Domain\Service\Stream\TextDelta;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;
use App\Domain\Service\Stream\Usage;
use App\Infrastructure\AI\OpenAIStreamingClient;
use App\Infrastructure\AI\RetryPolicy;
use App\Infrastructure\AI\TransportException;
use Tests\Unit\Infrastructure\FakeSseTransport;

/**
 * @param list<array<string, mixed>> $deltas
 */
function openaiResponse(array $deltas, string $finishReason, int $prompt = 100, int $cached = 0, int $completion = 10): string {
    $chunks = array_map(static fn (array $d): array => ['choices' => [['index' => 0, 'delta' => $d, 'finish_reason' => null]], 'usage' => null], $deltas);
    $chunks[] = ['choices' => [['index' => 0, 'delta' => new stdClass(), 'finish_reason' => $finishReason]], 'usage' => null];
    $chunks[] = ['choices' => [], 'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'prompt_tokens_details' => ['cached_tokens' => $cached]]];

    return FakeSseTransport::openai($chunks);
}

/**
 * @return list<array<string, mixed>>
 */
function openaiToolCall(string $id, string $arguments): array {
    return [
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [['index' => 0, 'id' => $id, 'type' => 'function', 'function' => ['name' => 'echo', 'arguments' => '']]]],
        ['tool_calls' => [['index' => 0, 'function' => ['arguments' => substr($arguments, 0, 4)]]]],
        ['tool_calls' => [['index' => 0, 'function' => ['arguments' => substr($arguments, 4)]]]],
    ];
}

/**
 * @param list<float> $sleeps
 */
function openaiClient(FakeSseTransport $transport, array &$sleeps = [], int $maxSteps = 5): OpenAIStreamingClient {
    $client = new OpenAIStreamingClient('key', 1024, $maxSteps, $transport, new RetryPolicy(sleep: function (float $s) use (&$sleeps): void {
        $sleeps[] = $s;
    }));
    $client->setTools([FakeSseTransport::echoTool()]);

    return $client;
}

it('streams text and reports uncached input, output and cached tokens', function (int $readSize): void {
    $transport = new FakeSseTransport([openaiResponse([['content' => 'Hel'], ['content' => 'lo']], 'stop', prompt: 1500, cached: 1024, completion: 12)], $readSize);

    $events = FakeSseTransport::collect(openaiClient($transport)->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'gpt-6-luna', 'System'));

    expect($events)->toEqual([
        new TextDelta('Hel'),
        new TextDelta('lo'),
        new StreamEnd(StopReason::EndTurn, new Usage(476, 12, 1024)),
    ]);

    $payload = $transport->payloads()[0];
    expect($payload['stream_options'])->toBe(['include_usage' => true])
        ->and($payload['reasoning_effort'])->toBe('none')
        ->and($payload['messages'][0])->toBe(['role' => 'system', 'content' => 'System'])
    ;
})->with([1, 5, 4096]);

it('runs tool calls and sums usage across the continuation', function (): void {
    $transport = new FakeSseTransport([
        openaiResponse([['content' => 'Let me echo. '], ...openaiToolCall('call_1', '{"text":"ping"}')], 'tool_calls', prompt: 200, completion: 20),
        openaiResponse([['content' => 'Done.']], 'stop', prompt: 260, cached: 128, completion: 5),
    ]);

    $events = FakeSseTransport::collect(openaiClient($transport)->streamChatRealtime([['role' => 'user', 'content' => 'Echo ping']], 'gpt-6-luna'));

    expect($events)->toEqual([
        new TextDelta('Let me echo. '),
        new ToolCall('call_1', 'echo', ['text' => 'ping']),
        new ToolResult('call_1', 'echo', 'echo: ping'),
        new TextDelta('Done.'),
        new StreamEnd(StopReason::EndTurn, new Usage(332, 25, 128)),
    ]);

    expect(array_slice($transport->payloads()[1]['messages'], 1))->toBe([
        ['role' => 'assistant', 'content' => 'Let me echo. ', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'echo', 'arguments' => '{"text":"ping"}']]]],
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'echo: ping'],
    ]);
});

it('stops with ToolLimit after five requests that all ask for tools', function (): void {
    $transport = new FakeSseTransport(array_fill(0, 5, openaiResponse(openaiToolCall('call_x', '{"text":"again"}'), 'tool_calls')));

    $events = FakeSseTransport::collect(openaiClient($transport)->streamChatRealtime([['role' => 'user', 'content' => 'Loop']], 'gpt-6-luna'));

    expect($transport->bodies)->toHaveCount(5)
        ->and(array_filter($events, static fn (object $e): bool => $e instanceof ToolResult))->toHaveCount(4)
        ->and(end($events))->toEqual(new StreamEnd(StopReason::ToolLimit, new Usage(500, 50)))
    ;
});

it('maps finish reasons', function (string $finishReason, StopReason $expected): void {
    $transport = new FakeSseTransport([openaiResponse([['content' => 'x']], $finishReason)]);

    $events = FakeSseTransport::collect(openaiClient($transport)->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'gpt-4o-mini'));

    expect(end($events))->toEqual(new StreamEnd($expected, new Usage(100, 10)));
})->with([
    ['stop', StopReason::EndTurn],
    ['length', StopReason::MaxTokens],
    ['content_filter', StopReason::Refusal],
]);

it('retries a 503 and a cut stream, but not a 401', function (): void {
    $sleeps = [];
    $transport = new FakeSseTransport([
        new TransportException('HTTP 503', 503, 'busy'),
        FakeSseTransport::openai([], done: false),
        openaiResponse([['content' => 'ok']], 'stop'),
        new TransportException('HTTP 401', 401, '{"error":{"message":"bad key"}}'),
    ]);
    $client = openaiClient($transport, $sleeps);

    expect(FakeSseTransport::collect($client->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'gpt-6-luna'))[0])->toEqual(new TextDelta('ok'))
        ->and($sleeps)->toHaveCount(2)
    ;

    expect(fn () => FakeSseTransport::collect($client->streamChatRealtime([['role' => 'user', 'content' => 'Hi']], 'gpt-6-luna')))
        ->toThrow(RuntimeException::class, 'HTTP error from AI engine (401)')
    ;
    expect($transport->bodies)->toHaveCount(4)->and($sleeps)->toHaveCount(2);
});
