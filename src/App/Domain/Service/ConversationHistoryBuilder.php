<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\Message;

/**
 * Turns stored messages into provider-neutral history that fits a token budget.
 *
 * Newest messages are kept whole; older ones are added until the budget is spent, and the oldest
 * one that only partly fits is truncated. Tool calls are replayed as one compact line each, so the
 * model sees document IDs from earlier turns without provider-specific tool blocks.
 */
final readonly class ConversationHistoryBuilder {
    // Not worth sending a truncated message shorter than this
    private const int MIN_TRUNCATED_TOKENS = 25;
    private const int MAX_REPLAYED_VALUE_CHARS = 100;
    private const int MAX_REPLAYED_RESULT_CHARS = 200;

    public function __construct(
        private int $maxTokens = 8000,
    ) {}

    /**
     * Rough estimate: about 4 characters per token for English prose. Code and non-Latin
     * scripts use more tokens per character, so the budget should keep some headroom.
     */
    public static function estimateTokens(string $text): int {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * @param list<Message> $messages Oldest first
     *
     * @return list<array{role: string, content: string}>
     */
    public function build(array $messages): array {
        $entries = [];
        foreach ($messages as $message) {
            $content = $this->contentFor($message);
            if (mb_trim($content) !== '') {
                $entries[] = ['role' => $message->role, 'content' => $content];
            }
        }

        $kept = [];
        $remaining = $this->maxTokens;

        for ($i = \count($entries) - 1; $i >= 0; --$i) {
            $tokens = self::estimateTokens($entries[$i]['content']);

            if ($tokens <= $remaining || $kept === []) {
                $kept[] = $entries[$i];
                $remaining -= $tokens;

                continue;
            }

            if ($remaining >= self::MIN_TRUNCATED_TOKENS) {
                $kept[] = [
                    'role' => $entries[$i]['role'],
                    'content' => mb_substr($entries[$i]['content'], 0, $remaining * 4) . '… [truncated]',
                ];
            }

            break;
        }

        $kept = array_reverse($kept);

        // Providers expect the conversation to open with a user turn
        while ($kept !== [] && $kept[0]['role'] === Message::ROLE_ASSISTANT) {
            array_shift($kept);
        }

        return $kept;
    }

    /**
     * The text sent to the model for one message: notices are left out, tool calls replayed.
     */
    public function contentFor(Message $message): string {
        if ($message->parts === null) {
            return $message->content;
        }

        $text = '';
        $calls = [];
        $results = [];

        foreach ($message->parts as $part) {
            match ($part['type'] ?? null) {
                Message::PART_TEXT => $text .= (string) ($part['text'] ?? ''),
                Message::PART_TOOL_CALL => $calls[] = $part,
                Message::PART_TOOL_RESULT => $results[(string) ($part['id'] ?? '')] = $part,
                default => null,
            };
        }

        $lines = [];
        foreach ($calls as $call) {
            $result = $results[(string) ($call['id'] ?? '')] ?? null;
            $lines[] = \sprintf(
                '[%s %s → %s]',
                (string) ($call['name'] ?? 'tool'),
                $this->summarizeInput(\is_array($call['input'] ?? null) ? $call['input'] : []),
                $result === null ? 'no result' : $this->shorten((string) ($result['content'] ?? ''), self::MAX_REPLAYED_RESULT_CHARS),
            );
        }

        return implode("\n\n", array_filter([mb_trim($text), implode("\n", $lines)], fn (string $part): bool => $part !== ''));
    }

    /**
     * @param array<mixed> $input
     */
    private function summarizeInput(array $input): string {
        // Long values (document bodies) are already stored elsewhere and would cost tokens every turn
        $short = array_filter(
            $input,
            fn (mixed $value): bool => !\is_string($value) || mb_strlen($value) <= self::MAX_REPLAYED_VALUE_CHARS,
        );

        return (string) json_encode($short === [] ? new \stdClass() : $short, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function shorten(string $value, int $maxChars): string {
        return mb_strlen($value) > $maxChars ? mb_substr($value, 0, $maxChars) . '…' : $value;
    }
}
