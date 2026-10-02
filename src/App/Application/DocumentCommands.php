<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Model\Document;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Infrastructure\EventBus\EventBusInterface;

/**
 * Reading, editing and deleting the documents of a chat. Commands return an HTTP-like status code.
 *
 * A document is only reachable through the chat it belongs to. Its chat's owner may change it,
 * anyone may read the documents of a public chat.
 */
final class DocumentCommands {
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly EventBusInterface $eventBus,
    ) {}

    /**
     * The document at the given version, or the latest one for null or a version it does not have.
     */
    public function find(int $userId, string $chatId, string $documentId, ?int $version = null): ?Document {
        if ($this->access($userId, $chatId, $documentId, write: false) !== 200) {
            return null;
        }

        $document = $version !== null ? $this->documentRepository->findWithContent($documentId, $version) : null;

        // Without a stored version the repository returns the document without content
        return $document?->content !== null ? $document : $this->documentRepository->findWithContent($documentId);
    }

    /**
     * @return list<array{version: int, createdAt: int}> Newest first
     */
    public function versions(int $userId, string $chatId, string $documentId): array {
        if ($this->access($userId, $chatId, $documentId, write: false) !== 200) {
            return [];
        }

        return $this->documentRepository->getVersions($documentId);
    }

    /**
     * Store the content as a new version.
     */
    public function update(int $userId, string $chatId, string $documentId, string $content): int {
        $status = $this->access($userId, $chatId, $documentId, write: true);
        if ($status !== 200) {
            return $status;
        }

        $document = $this->documentRepository->findWithContent($documentId);
        if ($document === null) {
            return 404;
        }

        $updated = $document->updateContent($content);
        $this->documentRepository->save($updated);

        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $documentId,
            chatId: $chatId,
            userId: $userId,
            action: 'updated',
            version: $updated->currentVersion,
            kind: $document->kind,
            language: $document->language,
        ));

        return 204;
    }

    public function delete(int $userId, string $chatId, string $documentId): int {
        $status = $this->access($userId, $chatId, $documentId, write: true);
        if ($status !== 200) {
            return $status;
        }

        $this->documentRepository->delete($documentId);

        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $documentId,
            chatId: $chatId,
            userId: $userId,
            action: 'deleted',
        ));

        return 204;
    }

    private function access(int $userId, string $chatId, string $documentId, bool $write): int {
        $document = $this->documentRepository->find($documentId);
        $chat = $this->chatRepository->find($chatId);

        if ($document === null || $chat === null || $document->chatId !== $chat->id) {
            return 404;
        }

        if ($chat->isOwnedBy($userId) || (!$write && $chat->isPublic())) {
            return 200;
        }

        return 403;
    }
}
