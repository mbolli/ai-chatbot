<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Command;

use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\SuggestionsUpdatedEvent;
use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\SuggestionRepositoryInterface;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\EventBus\EventBusInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SuggestionCommandHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly SuggestionRepositoryInterface $suggestionRepository,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly EventBusInterface $eventBus,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        /** @var null|RouteResult $routeResult */
        $routeResult = $request->getAttribute(RouteResult::class);
        $routeName = $routeResult?->getMatchedRouteName() ?? '';

        return match (true) {
            str_ends_with($routeName, '.accept') => $this->accept($request),
            str_ends_with($routeName, '.dismiss') => $this->dismiss($request),
            default => new EmptyResponse(404),
        };
    }

    /**
     * Applies the suggestion as a new document version.
     */
    public function accept(ServerRequestInterface $request): ResponseInterface {
        $resolved = $this->resolve($request);
        if ($resolved instanceof ResponseInterface) {
            return $resolved;
        }

        [$userId, $suggestion, $document] = $resolved;
        $content = $document->content ?? '';

        // The text changed since the suggestion was made
        if (!$suggestion->appliesTo($content)) {
            return $this->reject($userId, $suggestion, $document);
        }

        $updatedDocument = $document->updateContent($suggestion->applyTo($content));
        $this->documentRepository->save($updatedDocument);
        $this->suggestionRepository->save($suggestion->accept());

        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $document->id,
            chatId: $document->chatId,
            userId: $userId,
            action: 'updated',
            version: $updatedDocument->currentVersion,
            kind: $document->kind,
            language: $document->language,
        ));

        return new EmptyResponse(204);
    }

    public function dismiss(ServerRequestInterface $request): ResponseInterface {
        $resolved = $this->resolve($request);
        if ($resolved instanceof ResponseInterface) {
            return $resolved;
        }

        [$userId, $suggestion, $document] = $resolved;

        return $this->reject($userId, $suggestion, $document);
    }

    private function reject(int $userId, Suggestion $suggestion, Document $document): ResponseInterface {
        $this->suggestionRepository->save($suggestion->reject());

        $this->eventBus->emit($userId, new SuggestionsUpdatedEvent(
            documentId: $document->id,
            chatId: $document->chatId,
            userId: $userId,
            action: SuggestionsUpdatedEvent::ACTION_DISMISSED,
        ));

        return new EmptyResponse(204);
    }

    /**
     * Loads a pending suggestion and its document, checking that the user owns the chat.
     *
     * @return array{int, Suggestion, Document}|ResponseInterface
     */
    private function resolve(ServerRequestInterface $request): array|ResponseInterface {
        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $suggestion = $this->suggestionRepository->find((string) $request->getAttribute('id'));
        $document = $suggestion !== null ? $this->documentRepository->findWithContent($suggestion->documentId) : null;

        if ($suggestion === null || $document === null) {
            return new EmptyResponse(404);
        }

        $chat = $this->chatRepository->find($document->chatId);
        if ($chat === null || !$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        // Already accepted or dismissed, e.g. a double click
        if (!$suggestion->isPending()) {
            return new EmptyResponse(204);
        }

        return [$userId, $suggestion, $document];
    }
}
