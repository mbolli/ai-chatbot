<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Command;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Message;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Infrastructure\AI\LLPhantAIService;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\EventBus\EventBusInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ChatCommandHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageRepositoryInterface $messageRepository,
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
            str_ends_with($routeName, '.model') => $this->model($request),
            default => new EmptyResponse(404),
        };
    }

    public function create(ServerRequestInterface $request): ResponseInterface {
        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $data = $this->getRequestData($request);

        $chat = Chat::create(
            userId: $userId,
            model: $data['_model'] ?? $data['model'] ?? LLPhantAIService::DEFAULT_MODEL,
            visibility: $data['_visibility'] ?? $data['visibility'] ?? 'private',
            title: $data['_title'] ?? $data['title'] ?? null,
        );

        $this->chatRepository->save($chat);

        // If an initial message was provided, save it (will be sent when user visits chat page)
        $initialMessage = $data['_message'] ?? $data['message'] ?? null;
        if (!empty($initialMessage)) {
            $message = Message::user($chat->id, $initialMessage);
            $this->messageRepository->save($message);
        }

        // Emit event with redirect URL - SSE listener will handle the redirect
        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chat->id,
            userId: $userId,
            action: 'created',
            redirectUrl: "/chat/{$chat->id}",
        ));

        return new EmptyResponse(204);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('id');

        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

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

        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

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

    public function model(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('id');

        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new EmptyResponse(404);
        }

        if (!$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        $data = $this->getRequestData($request);
        $model = $data['model'] ?? $chat->model;

        $updatedChat = $chat->updateModel($model);
        $this->chatRepository->save($updatedChat);

        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'model_changed',
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
                $data = $data['datastar'];
            }

            return $data;
        }

        // Form data
        return $request->getParsedBody() ?? [];
    }
}
