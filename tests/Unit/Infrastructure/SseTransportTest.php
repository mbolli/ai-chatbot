<?php

declare(strict_types=1);

use App\Infrastructure\AI\ChunkedDecoder;
use App\Infrastructure\AI\RetryPolicy;
use App\Infrastructure\AI\SseEvent;
use App\Infrastructure\AI\SseHttpTransport;
use App\Infrastructure\AI\SseParser;
use App\Infrastructure\AI\TransportException;

it('parses SSE events across arbitrary read boundaries', function (int $readSize): void {
    $stream = ": keep-alive\r\n\r\nevent: message_start\r\ndata: {\"a\":1}\r\n\r\n"
        . "data: line one\ndata: line two\n\n"
        . "event: ping\ndata:no-space\n\n"
        . "data: [DONE]\n\n";

    $parser = new SseParser();
    $events = [];
    foreach (str_split($stream, $readSize) as $bytes) {
        array_push($events, ...$parser->feed($bytes));
    }

    expect($events)->toEqual([
        new SseEvent('{"a":1}', 'message_start'),
        new SseEvent("line one\nline two"),
        new SseEvent('no-space', 'ping'),
        new SseEvent('[DONE]'),
    ])->and($parser->flush())->toBeNull();
})->with([1, 2, 7, 1000]);

it('flushes an event left unterminated by a closed connection', function (string $tail): void {
    $parser = new SseParser();

    expect($parser->feed("data: {\"x\":1}{$tail}"))->toBe([])
        ->and($parser->flush())->toEqual(new SseEvent('{"x":1}'))
    ;
})->with(['', "\n"]);

it('de-chunks then parses when chunk boundaries split SSE lines', function (): void {
    $sse = str_repeat("event: content_block_delta\ndata: {\"delta\":\"abcdefghij\"}\n\n", 10);
    $raw = '';
    foreach (str_split($sse, 13) as $piece) {
        $raw .= dechex(strlen($piece)) . "\r\n" . $piece . "\r\n";
    }
    $raw .= "0\r\n\r\n";

    $decoder = new ChunkedDecoder();
    $parser = new SseParser();
    $events = [];
    foreach (str_split($raw, 5) as $read) {
        array_push($events, ...$parser->feed($decoder->decode($read)));
    }

    expect($events)->toHaveCount(10)
        ->and($events[9])->toEqual(new SseEvent('{"delta":"abcdefghij"}', 'content_block_delta'))
        ->and($decoder->isDone())->toBeTrue()
    ;
});

it('parses the status line and lowercases header names', function (): void {
    [$status, $headers] = SseHttpTransport::parseHead("HTTP/1.1 429 Too Many Requests\r\nRetry-After: 7\r\nTransfer-Encoding: chunked");

    expect($status)->toBe(429)
        ->and($headers)->toBe(['retry-after' => '7', 'transfer-encoding' => 'chunked'])
    ;
});

it('rejects a response that is not HTTP', function (): void {
    expect(fn () => SseHttpTransport::parseHead('garbage'))->toThrow(TransportException::class);
});

it('retries only transient statuses and connection failures', function (?int $status, bool $retry): void {
    expect((new RetryPolicy())->shouldRetry(new TransportException('x', $status), 1))->toBe($retry);
})->with([
    [null, true],
    [408, true], [409, true], [429, true], [500, true], [502, true], [503, true], [504, true], [529, true],
    [400, false], [401, false], [403, false], [404, false], [413, false],
]);

it('allows three attempts and refuses a retry-after longer than the max delay', function (): void {
    $policy = new RetryPolicy(maxDelay: 20.0);

    expect($policy->shouldRetry(new TransportException('x', 529), 2))->toBeTrue()
        ->and($policy->shouldRetry(new TransportException('x', 529), 3))->toBeFalse()
        ->and($policy->shouldRetry(new TransportException('x', 429, '', 20.0), 1))->toBeTrue()
        ->and($policy->shouldRetry(new TransportException('x', 429, '', 60.0), 1))->toBeFalse()
    ;
});

it('backs off exponentially with jitter unless retry-after is given', function (): void {
    $policy = new RetryPolicy();

    foreach (range(1, 50) as $_) {
        expect($policy->delay(1))->toBeGreaterThanOrEqual(0.375)->toBeLessThanOrEqual(0.625)
            ->and($policy->delay(2))->toBeGreaterThanOrEqual(0.75)->toBeLessThanOrEqual(1.25)
            ->and($policy->delay(3))->toBeGreaterThanOrEqual(1.5)->toBeLessThanOrEqual(2.5)
        ;
    }

    expect($policy->delay(1, 3.0))->toBe(3.0);
});

it('parses retry-after seconds and ignores HTTP dates', function (?string $header, ?float $expected): void {
    expect(RetryPolicy::parseRetryAfter($header))->toBe($expected);
})->with([
    ['2', 2.0],
    [' 1.5 ', 1.5],
    ['-1', 0.0],
    ['Wed, 21 Oct 2026 07:28:00 GMT', null],
    ['', null],
    [null, null],
]);
