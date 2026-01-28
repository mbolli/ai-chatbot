<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler;

use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\Template\TemplateRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ChatHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly AIServiceInterface $aiService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('id');
        $user = AuthMiddleware::getUser($request);
        $userId = $user->id;
        $userInfo = [
            'id' => $user->id,
            'email' => $user->email,
            'isGuest' => $user->isGuest,
        ];

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new HtmlResponse('Chat not found', 404);
        }

        // Check access
        if (!$chat->isOwnedBy($userId) && !$chat->isPublic()) {
            return new HtmlResponse('Access denied', 403);
        }

        $messages = $this->messageRepository->findByChat($chatId);
        $chats = $this->chatRepository->findByUser($userId, 20);

        $models = $this->aiService->getAvailableModels();

        // Check if there's a pending user message that needs AI response
        // This happens when a chat was created with an initial message from home page
        $needsAiResponse = false;
        if (!empty($messages)) {
            $lastMessage = end($messages);
            if ($lastMessage->role === 'user') {
                $needsAiResponse = true;
            }
        }

        $html = $this->renderer->render('layout::default', [
            'title' => $chat->title ?? 'New Chat',
            'user' => $userInfo,
            'defaultModel' => $chat->model,
            'currentChatId' => $chat->id,
            'content' => $this->renderer->render('app::chat', [
                'chat' => $chat,
                'messages' => $messages,
                'chats' => $chats,
                'user' => $userInfo,
                'models' => $models,
                'needsAiResponse' => $needsAiResponse,
            ]),
        ]);

        return new HtmlResponse($html);
    }
}
