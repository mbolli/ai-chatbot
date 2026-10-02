<?php

declare(strict_types=1);

namespace App\Web;

/**
 * In-memory state that live views render from: the reply being streamed per chat and pending toasts per user.
 *
 * Lives in worker memory, so the server must run with a single worker.
 */
final class LiveState {
    /** @var array<string, array{messageId: string, content: string, thinking: string}> */
    private array $streams = [];

    /** @var array<int, list<array{id: string, message: string, expires: int, signals: array<string, mixed>}>> */
    private array $toasts = [];

    public function startStream(string $chatId, string $messageId): void {
        $this->streams[$chatId] = ['messageId' => $messageId, 'content' => '', 'thinking' => ''];
    }

    public function updateStream(string $chatId, string $messageId, ?string $content = null, ?string $thinking = null): void {
        $stream = $this->streams[$chatId] ?? ['messageId' => $messageId, 'content' => '', 'thinking' => ''];
        if ($stream['messageId'] !== $messageId) {
            return;
        }
        if ($content !== null) {
            $stream['content'] = $content;
        }
        if ($thinking !== null) {
            $stream['thinking'] = $thinking;
        }
        $this->streams[$chatId] = $stream;
    }

    public function endStream(string $chatId): void {
        unset($this->streams[$chatId]);
    }

    /**
     * @return null|array{messageId: string, content: string, thinking: string}
     */
    public function stream(string $chatId): ?array {
        return $this->streams[$chatId] ?? null;
    }

    /**
     * @param array<string, mixed> $signals client signals the toast sets when it appears
     */
    public function pushToast(int $userId, string $message, int $ttlSeconds = 8, array $signals = []): void {
        $now = time();
        $active = array_values(array_filter($this->toasts[$userId] ?? [], static fn (array $t): bool => $t['expires'] > $now));
        $active[] = ['id' => bin2hex(random_bytes(6)), 'message' => $message, 'expires' => $now + $ttlSeconds, 'signals' => $signals];
        $this->toasts[$userId] = \array_slice($active, -3);
    }

    /**
     * @return list<array{id: string, message: string, expires: int, signals: array<string, mixed>}>
     */
    public function toasts(int $userId): array {
        $now = time();

        return array_values(array_filter($this->toasts[$userId] ?? [], static fn (array $t): bool => $t['expires'] > $now));
    }
}
