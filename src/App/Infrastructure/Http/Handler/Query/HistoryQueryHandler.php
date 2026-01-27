<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Query;

use App\Domain\Model\Chat;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Infrastructure\Auth\AuthMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HistoryQueryHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        /** @var null|RouteResult $routeResult */
        $routeResult = $request->getAttribute(RouteResult::class);
        $routeName = $routeResult?->getMatchedRouteName() ?? '';

        return match (true) {
            str_ends_with($routeName, '.list') => $this->list($request),
            default => new JsonResponse(['error' => 'Not found'], 404),
        };
    }

    public function list(ServerRequestInterface $request): ResponseInterface {
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $params = $request->getQueryParams();
        $limit = min((int) ($params['limit'] ?? 50), 100);
        $offset = max((int) ($params['offset'] ?? 0), 0);

        $chats = $this->chatRepository->findByUser($userId, $limit, $offset);

        return new JsonResponse([
            'chats' => array_map(fn (Chat $c): array => $c->toArray(), $chats),
        ]);
    }
}
