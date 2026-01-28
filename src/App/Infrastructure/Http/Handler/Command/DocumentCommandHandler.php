<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Command;

use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Model\Document;
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

final class DocumentCommandHandler implements RequestHandlerInterface {
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
            str_ends_with($routeName, '.create') => $this->create($request),
            str_ends_with($routeName, '.update') => $this->update($request),
            str_ends_with($routeName, '.delete') => $this->delete($request),
            default => new EmptyResponse(404),
        };
    }

    public function create(ServerRequestInterface $request): ResponseInterface {
        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $data = $this->getRequestData($request);

        $chatId = $data['chat_id'] ?? null;
        $kind = $data['kind'] ?? Document::KIND_TEXT;
        $title = $data['title'] ?? 'Untitled';
        $content = $data['content'] ?? '';
        $language = $data['language'] ?? null;

        if ($chatId === null) {
            return new JsonResponse(['error' => 'chat_id is required'], 400);
        }

        // Verify user owns the chat
        $chat = $this->chatRepository->find($chatId);
        if ($chat === null || !$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        // Create document based on kind
        $document = match ($kind) {
            Document::KIND_CODE => Document::code($chatId, $title, $content, $language ?? 'python'),
            Document::KIND_SHEET => Document::sheet($chatId, $title, $content),
            Document::KIND_IMAGE => Document::image($chatId, $title, $content),
            default => Document::text($chatId, $title, $content),
        };

        $this->documentRepository->save($document);

        // Emit event for SSE (include kind/language for Pyodide lazy loading)
        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $document->id,
            chatId: $chatId,
            userId: $userId,
            action: 'created',
            kind: $document->kind,
            language: $document->language,
        ));

        return new JsonResponse([
            'id' => $document->id,
            'kind' => $document->kind,
            'title' => $document->title,
        ], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface {
        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $documentId = $request->getAttribute('id');
        $data = $this->getRequestData($request);

        $document = $this->documentRepository->findWithContent($documentId);

        if ($document === null) {
            return new EmptyResponse(404);
        }

        // Verify user owns the chat that owns this document
        $chat = $this->chatRepository->find($document->chatId);
        if ($chat === null || !$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        // Update content if provided
        $content = $data['content'] ?? null;
        $title = $data['title'] ?? null;

        $updatedDocument = $document;

        if ($content !== null) {
            $updatedDocument = $updatedDocument->updateContent($content);
        }

        if ($title !== null) {
            $updatedDocument = $updatedDocument->updateTitle($title);
        }

        $this->documentRepository->save($updatedDocument);

        // Emit event for SSE
        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $document->id,
            chatId: $document->chatId,
            userId: $userId,
            action: 'updated',
            version: $updatedDocument->currentVersion,
        ));

        return new EmptyResponse(204);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface {
        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $documentId = $request->getAttribute('id');

        $document = $this->documentRepository->find($documentId);

        if ($document === null) {
            return new EmptyResponse(404);
        }

        // Verify user owns the chat that owns this document
        $chat = $this->chatRepository->find($document->chatId);
        if ($chat === null || !$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        $this->documentRepository->delete($documentId);

        // Emit event for SSE
        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $documentId,
            chatId: $document->chatId,
            userId: $userId,
            action: 'deleted',
        ));

        return new EmptyResponse(204);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestData(ServerRequestInterface $request): array {
        $contentType = $request->getHeaderLine('content-type');

        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            $data = json_decode($body, true);

            return \is_array($data) ? $data : [];
        }

        $parsed = $request->getParsedBody();

        return \is_array($parsed) ? $parsed : [];
    }
}
