<?php

declare(strict_types=1);

use App\Infrastructure\AI\ChunkedDecoder;

/**
 * Encode a payload as HTTP chunked transfer encoding with the given chunk sizes.
 *
 * @param list<int> $sizes
 */
function chunked(string $payload, array $sizes): string {
    $out = '';
    foreach ($sizes as $size) {
        $piece = substr($payload, 0, $size);
        $payload = substr($payload, $size);
        $out .= dechex(strlen($piece)) . "\r\n" . $piece . "\r\n";
    }

    return $out . "0\r\n\r\n";
}

it('decodes chunks whose boundaries split SSE lines', function (): void {
    $sse = "data: {\"text\":\"Hello\"}\n\ndata: {\"text\":\" World\"}\n\n";
    $decoder = new ChunkedDecoder();

    expect($decoder->decode(chunked($sse, [10, 17, 100])))->toBe($sse)
        ->and($decoder->isDone())->toBeTrue()
    ;
});

it('decodes when socket reads split chunk headers and data at arbitrary points', function (): void {
    $sse = str_repeat("data: {\"delta\":\"abcdefghij\"}\n\n", 20);
    $raw = chunked($sse, [7, 300, 13, 1000]);

    foreach ([1, 2, 3, 5, 64] as $readSize) {
        $decoder = new ChunkedDecoder();
        $out = '';
        foreach (str_split($raw, $readSize) as $read) {
            $out .= $decoder->decode($read);
        }

        expect($out)->toBe($sse)
            ->and($decoder->isDone())->toBeTrue()
        ;
    }
});

it('ignores chunk extensions', function (): void {
    $decoder = new ChunkedDecoder();

    expect($decoder->decode("5;name=value\r\nhello\r\n0\r\n\r\n"))->toBe('hello');
});
