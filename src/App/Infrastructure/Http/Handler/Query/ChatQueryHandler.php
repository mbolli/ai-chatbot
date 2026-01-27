<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Query;

use App\Domain\Model\Message;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ChatQueryHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageRepositoryInterface $messageRepository,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        /** @var null|RouteResult $routeResult */
        $routeResult = $request->getAttribute(RouteResult::class);
        $routeName = $routeResult?->getMatchedRouteName() ?? '';

        return match (true) {
            str_ends_with($routeName, '.show') => $this->show($request),
            str_ends_with($routeName, '.messages') => $this->messages($request),
            default => new JsonResponse(['error' => 'Not found'], 404),
        };
    }

    public function show(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('id');
        $userId = 1; // TODO: Get from auth

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new JsonResponse(['error' => 'Chat not found'], 404);
        }

        if (!$chat->isOwnedBy($userId) && !$chat->isPublic()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        return new JsonResponse($chat->toArray());
    }

    public function messages(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('id');
        $userId = 1; // TODO: Get from auth

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new JsonResponse(['error' => 'Chat not found'], 404);
        }

        if (!$chat->isOwnedBy($userId) && !$chat->isPublic()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $messages = $this->messageRepository->findByChat($chatId);

        return new JsonResponse([
            'messages' => array_map(fn (Message $m): array => $m->toArray(), $messages),
        ]);
    }
}
