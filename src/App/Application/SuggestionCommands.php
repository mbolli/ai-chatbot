<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\SuggestionsUpdatedEvent;
use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\SuggestionRepositoryInterface;
use App\Infrastructure\EventBus\EventBusInterface;

/**
 * Accepting and dismissing the writing suggestions of a chat's documents. Methods return an HTTP-like status code.
 */
final class SuggestionCommands {
    public function __construct(
        private readonly SuggestionRepositoryInterface $suggestionRepository,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly EventBusInterface $eventBus,
    ) {}

    /**
     * Pending suggestions that still apply to the document's latest version, for the chat's owner only.
     *
     * @return list<Suggestion>
     */
    public function pending(int $userId, string $chatId, Document $document): array {
        $chat = $this->chatRepository->find($chatId);
        if ($chat === null || !$chat->isOwnedBy($userId) || $document->chatId !== $chatId || !$document->isText()) {
            return [];
        }

        $content = $document->content ?? '';

        return array_values(array_filter(
            $this->suggestionRepository->findPendingByDocument($document->id),
            static fn (Suggestion $suggestion): bool => $suggestion->appliesTo($content),
        ));
    }

    /**
     * Applies the suggestion as a new document version.
     */
    public function accept(int $userId, string $chatId, string $suggestionId): int {
        $resolved = $this->resolve($userId, $chatId, $suggestionId);
        if (\is_int($resolved)) {
            return $resolved;
        }

        [$suggestion, $document] = $resolved;
        $content = $document->content ?? '';

        // The text changed since the suggestion was made
        if (!$suggestion->appliesTo($content)) {
            return $this->reject($userId, $suggestion, $document);
        }

        $updated = $document->updateContent($suggestion->applyTo($content));
        $this->documentRepository->save($updated);
        $this->suggestionRepository->save($suggestion->accept());

        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $document->id,
            chatId: $document->chatId,
            userId: $userId,
            action: 'updated',
            version: $updated->currentVersion,
            kind: $document->kind,
            language: $document->language,
        ));

        return 204;
    }

    public function dismiss(int $userId, string $chatId, string $suggestionId): int {
        $resolved = $this->resolve($userId, $chatId, $suggestionId);
        if (\is_int($resolved)) {
            return $resolved;
        }

        [$suggestion, $document] = $resolved;

        return $this->reject($userId, $suggestion, $document);
    }

    private function reject(int $userId, Suggestion $suggestion, Document $document): int {
        $this->suggestionRepository->save($suggestion->reject());

        $this->eventBus->emit($userId, new SuggestionsUpdatedEvent(
            documentId: $document->id,
            chatId: $document->chatId,
            userId: $userId,
            action: SuggestionsUpdatedEvent::ACTION_DISMISSED,
        ));

        return 204;
    }

    /**
     * Loads a pending suggestion and the latest version of its document, which must belong to the user's chat.
     *
     * @return array{Suggestion, Document}|int
     */
    private function resolve(int $userId, string $chatId, string $suggestionId): array|int {
        $suggestion = $this->suggestionRepository->find($suggestionId);
        $document = $suggestion !== null ? $this->documentRepository->findWithContent($suggestion->documentId) : null;
        $chat = $this->chatRepository->find($chatId);

        if ($suggestion === null || $document === null || $chat === null || $document->chatId !== $chat->id) {
            return 404;
        }

        if (!$chat->isOwnedBy($userId)) {
            return 403;
        }

        // Already accepted or dismissed, e.g. a double click
        if (!$suggestion->isPending()) {
            return 204;
        }

        return [$suggestion, $document];
    }
}
