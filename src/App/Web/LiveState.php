<?php

declare(strict_types=1);

namespace App\Web;

/**
 * In-memory state that live views render from: the reply being streamed per chat, pending toasts per user
 * and the document the reply last asked to show per chat.
 *
 * Lives in worker memory, so the server must run with a single worker.
 */
final class LiveState {
    /** A tab that renders a document request later than this, e.g. after a reconnect, ignores it */
    private const float DOCUMENT_REQUEST_TTL = 10.0;

    /** @var array<string, array{messageId: string, content: string, thinking: string}> */
    private array $streams = [];

    /** @var array<int, list<array{id: string, message: string, expires: int}>> */
    private array $toasts = [];

    /** @var array<string, array{documentId: string, seq: int, at: float}> */
    private array $documentRequests = [];

    private int $documentRequestSeq = 0;

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

    public function pushToast(int $userId, string $message, int $ttlSeconds = 8): void {
        $now = time();
        $active = array_values(array_filter($this->toasts[$userId] ?? [], static fn (array $t): bool => $t['expires'] > $now));
        $active[] = ['id' => bin2hex(random_bytes(6)), 'message' => $message, 'expires' => $now + $ttlSeconds];
        $this->toasts[$userId] = \array_slice($active, -3);
    }

    /**
     * @return list<array{id: string, message: string, expires: int}>
     */
    public function toasts(int $userId): array {
        $now = time();

        return array_values(array_filter($this->toasts[$userId] ?? [], static fn (array $t): bool => $t['expires'] > $now));
    }

    /**
     * Ask the tabs viewing a chat to open a document; each tab acts on a request once, by its seq.
     */
    public function requestDocument(string $chatId, string $documentId): void {
        $now = microtime(true);
        $this->documentRequests = array_filter($this->documentRequests, static fn (array $r): bool => $now - $r['at'] <= self::DOCUMENT_REQUEST_TTL);
        $this->documentRequests[$chatId] = ['documentId' => $documentId, 'seq' => ++$this->documentRequestSeq, 'at' => $now];
    }

    /**
     * @return null|array{documentId: string, seq: int}
     */
    public function documentRequest(string $chatId): ?array {
        $request = $this->documentRequests[$chatId] ?? null;
        if ($request === null || microtime(true) - $request['at'] > self::DOCUMENT_REQUEST_TTL) {
            return null;
        }

        return ['documentId' => $request['documentId'], 'seq' => $request['seq']];
    }
}
