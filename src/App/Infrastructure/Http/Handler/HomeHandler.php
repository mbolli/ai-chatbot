<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler;

use App\Domain\Repository\ChatRepositoryInterface;
use App\Infrastructure\Template\TemplateRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HomeHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly ChatRepositoryInterface $chatRepository,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        // TODO: Get user ID from session/auth middleware
        $userId = 1; // Guest user for now

        $chats = $this->chatRepository->findByUser($userId, 20);

        $html = $this->renderer->render('layout::default', [
            'title' => 'AI Chatbot',
            'content' => $this->renderer->render('app::home', [
                'chats' => $chats,
            ]),
        ]);

        return new HtmlResponse($html);
    }
}
