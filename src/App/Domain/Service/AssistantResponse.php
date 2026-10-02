<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\Message;
use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;

/**
 * Collects one streamed assistant response into display content and ordered message parts.
 */
final class AssistantResponse {
    public const string NOTICE_EMPTY = 'empty';
    public const string NOTICE_ERROR = 'error';

    // Document bodies live in the documents table, the parts only need enough to show what was sent
    private const int MAX_STORED_INPUT_CHARS = 500;
    private const int MAX_STORED_RESULT_CHARS = 1000;

    private string $text = '';

    /**
     * @var list<array<string, mixed>>
     */
    private array $parts = [];

    /**
     * @var list<string>
     */
    private array $notices = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $toolInputs = [];

    private int $toolCallCount = 0;
    private bool $hasSuccessfulToolResult = false;

    public static function noticeFor(StopReason $stopReason): ?string {
        return match ($stopReason) {
            StopReason::MaxTokens => '⚠️ *Response cut off at the length limit.*',
            StopReason::Refusal => '⚠️ *The model declined to answer this request.*',
            StopReason::ToolLimit => '⚠️ *Stopped after reaching the tool step limit.*',
            default => null,
        };
    }

    public function addText(string $text): void {
        $this->text .= $text;

        $last = array_key_last($this->parts);
        if ($last !== null && $this->parts[$last]['type'] === Message::PART_TEXT) {
            $this->parts[$last]['text'] .= $text;

            return;
        }

        $this->parts[] = ['type' => Message::PART_TEXT, 'text' => $text];
    }

    public function addToolCall(ToolCall $call): void {
        ++$this->toolCallCount;
        $this->toolInputs[$call->id] = $call->input;
        $this->parts[] = [
            'type' => Message::PART_TOOL_CALL,
            'id' => $call->id,
            'name' => $call->name,
            'input' => array_map(
                fn (mixed $value): mixed => \is_string($value) ? self::truncate($value, self::MAX_STORED_INPUT_CHARS) : $value,
                $call->input,
            ),
        ];
    }

    public function addToolResult(ToolResult $result): void {
        $this->hasSuccessfulToolResult = $this->hasSuccessfulToolResult || !$result->isError;
        $this->parts[] = [
            'type' => Message::PART_TOOL_RESULT,
            'id' => $result->id,
            'name' => $result->name,
            'content' => self::truncate($result->content, self::MAX_STORED_RESULT_CHARS),
            'is_error' => $result->isError,
        ];
    }

    /**
     * A notice is shown to the user after the text but never replayed to the model.
     */
    public function addNotice(string $kind, string $text): void {
        $this->notices[] = $text;
        $this->parts[] = ['type' => Message::PART_NOTICE, 'kind' => $kind, 'text' => $text];
    }

    /**
     * The model's own text.
     */
    public function text(): string {
        return $this->text;
    }

    /**
     * What the user sees: the model's text followed by any notices.
     */
    public function content(): string {
        return implode("\n\n", array_filter([$this->text, ...$this->notices], fn (string $part): bool => $part !== ''));
    }

    /**
     * @return null|list<array<string, mixed>> Null when the response is plain text, which `content` already holds
     */
    public function parts(): ?array {
        foreach ($this->parts as $part) {
            if ($part['type'] !== Message::PART_TEXT) {
                return $this->parts;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed> The untruncated input of a tool call in this response
     */
    public function toolInput(string $toolCallId): array {
        return $this->toolInputs[$toolCallId] ?? [];
    }

    public function toolCallCount(): int {
        return $this->toolCallCount;
    }

    public function hasSuccessfulToolResult(): bool {
        return $this->hasSuccessfulToolResult;
    }

    private static function truncate(string $value, int $maxChars): string {
        return mb_strlen($value) > $maxChars ? mb_substr($value, 0, $maxChars) . '… [truncated]' : $value;
    }
}
