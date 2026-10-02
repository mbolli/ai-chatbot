<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Query;

use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\EventBus\EventBusInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DocumentQueryHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly EventBusInterface $eventBus,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        /** @var null|RouteResult $routeResult */
        $routeResult = $request->getAttribute(RouteResult::class);
        $routeName = $routeResult?->getMatchedRouteName() ?? '';

        return match (true) {
            str_ends_with($routeName, '.open') => $this->open($request),
            default => $this->show($request),
        };
    }

    private function show(ServerRequestInterface $request): ResponseInterface {
        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $documentId = $request->getAttribute('id');
        $queryParams = $request->getQueryParams();
        $version = isset($queryParams['version']) ? (int) $queryParams['version'] : null;

        $document = $this->documentRepository->findWithContent($documentId, $version);

        if ($document === null) {
            return new EmptyResponse(404);
        }

        // Verify user has access (owns the chat)
        $chat = $this->chatRepository->find($document->chatId);
        if ($chat === null) {
            return new EmptyResponse(404);
        }

        // Allow access if user owns the chat or chat is public
        if (!$chat->isOwnedBy($userId) && !$chat->isPublic()) {
            return new EmptyResponse(403);
        }

        // Get version history
        $versions = $this->documentRepository->getVersions($documentId);

        return new JsonResponse([
            'id' => $document->id,
            'chat_id' => $document->chatId,
            'message_id' => $document->messageId,
            'kind' => $document->kind,
            'title' => $document->title,
            'content' => $document->content,
            'language' => $document->language,
            'current_version' => $document->currentVersion,
            'versions' => $versions,
            'created_at' => $document->createdAt->getTimestamp(),
            'updated_at' => $document->updatedAt->getTimestamp(),
        ]);
    }

    /**
     * Open a document in the artifact panel via SSE.
     */
    private function open(ServerRequestInterface $request): ResponseInterface {
        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $documentId = $request->getAttribute('id');

        $document = $this->documentRepository->findWithContent($documentId);

        if ($document === null) {
            return new EmptyResponse(404);
        }

        // Verify user has access (owns the chat)
        $chat = $this->chatRepository->find($document->chatId);
        if ($chat === null) {
            return new EmptyResponse(404);
        }

        if (!$chat->isOwnedBy($userId) && !$chat->isPublic()) {
            return new EmptyResponse(403);
        }

        // Emit event to trigger SSE rendering
        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $document->id,
            chatId: $document->chatId,
            userId: $userId,
            action: 'updated', // Re-use created action to render the artifact
            kind: $document->kind,
            language: $document->language,
        ));

        return new EmptyResponse(204);
    }
}
