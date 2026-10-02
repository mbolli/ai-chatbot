<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\AI\SseEvent;
use App\Infrastructure\AI\SseParser;
use App\Infrastructure\AI\SseTransport;
use App\Infrastructure\AI\Tools\ToolInterface;
use App\Infrastructure\AI\TransportException;

/**
 * Replays recorded SSE byte streams through the real parser, split into small reads.
 */
final class FakeSseTransport implements SseTransport {
    /** @var list<string> */
    public array $bodies = [];

    /**
     * @param list<string|TransportException> $responses One per request, in order
     */
    public function __construct(
        private array $responses,
        private readonly int $readSize = 7,
    ) {}

    public function stream(string $path, array $headers, string $body): \Generator {
        $this->bodies[] = $body;
        $response = array_shift($this->responses) ?? throw new \LogicException('Unexpected request #' . \count($this->bodies));

        if ($response instanceof TransportException) {
            throw $response;
        }

        $parser = new SseParser();
        foreach (str_split($response, $this->readSize) as $bytes) {
            yield from $parser->feed($bytes);
        }

        $last = $parser->flush();
        if ($last instanceof SseEvent) {
            yield $last;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(): array {
        return array_map(static fn (string $body): array => json_decode($body, true, 512, JSON_THROW_ON_ERROR), $this->bodies);
    }

    /**
     * @return list<object>
     */
    public static function collect(\Generator $stream): array {
        $events = [];
        foreach ($stream as $event) {
            $events[] = $event;
        }

        return $events;
    }

    public static function echoTool(): ToolInterface {
        return new class implements ToolInterface {
            public function name(): string {
                return 'echo';
            }

            public function description(): string {
                return 'Echoes the input';
            }

            public function inputSchema(): array {
                return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]];
            }

            public function execute(array $input): string {
                return 'echo: ' . ($input['text'] ?? '');
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    public static function anthropic(array $events): string {
        return implode('', array_map(
            static fn (array $e): string => "event: {$e['type']}\r\ndata: " . json_encode($e, JSON_THROW_ON_ERROR) . "\r\n\r\n",
            $events,
        ));
    }

    /**
     * @param list<array<string, mixed>> $chunks
     */
    public static function openai(array $chunks, bool $done = true): string {
        return implode('', array_map(static fn (array $c): string => 'data: ' . json_encode($c, JSON_THROW_ON_ERROR) . "\n\n", $chunks))
            . ($done ? "data: [DONE]\n\n" : '');
    }
}
