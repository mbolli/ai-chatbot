<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Command;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Model\Chat;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Infrastructure\EventBus\EventBusInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ChatCommandHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly EventBusInterface $eventBus,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        // Dispatch to method based on route name
        /** @var null|RouteResult $routeResult */
        $routeResult = $request->getAttribute(RouteResult::class);
        $routeName = $routeResult?->getMatchedRouteName() ?? '';

        return match (true) {
            str_ends_with($routeName, '.create') => $this->create($request),
            str_ends_with($routeName, '.delete') => $this->delete($request),
            str_ends_with($routeName, '.visibility') => $this->visibility($request),
            default => new EmptyResponse(404),
        };
    }

    public function create(ServerRequestInterface $request): ResponseInterface {
        // TODO: Get user ID from auth
        $userId = 1;

        $data = $this->getRequestData($request);

        $chat = Chat::create(
            userId: $userId,
            model: $data['model'] ?? 'claude-3-5-sonnet',
            visibility: $data['visibility'] ?? 'private',
            title: $data['title'] ?? null,
        );

        $this->chatRepository->save($chat);

        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chat->id,
            userId: $userId,
            action: 'created',
        ));

        // Return the chat ID for redirect
        return new JsonResponse(['id' => $chat->id], 201);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('id');
        $userId = 1; // TODO: Get from auth

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new EmptyResponse(404);
        }

        if (!$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        $this->chatRepository->delete($chatId);

        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'deleted',
        ));

        return new EmptyResponse(204);
    }

    public function visibility(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('id');
        $userId = 1; // TODO: Get from auth

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new EmptyResponse(404);
        }

        if (!$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        $data = $this->getRequestData($request);
        $visibility = $data['visibility'] ?? 'private';

        $updatedChat = $chat->updateVisibility($visibility);
        $this->chatRepository->save($updatedChat);

        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'visibility_changed',
        ));

        return new EmptyResponse(204);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestData(ServerRequestInterface $request): array {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            $data = json_decode($body, true) ?? [];

            // Datastar wraps signals in 'datastar' key
            if (isset($data['datastar'])) {
                return $data['datastar'];
            }

            return $data;
        }

        // Form data
        return $request->getParsedBody() ?? [];
    }
}
