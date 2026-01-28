<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler;

use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\Template\TemplateRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HomeHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly AIServiceInterface $aiService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $user = AuthMiddleware::getUser($request);
        $userId = $user->id;
        $userInfo = [
            'id' => $user->id,
            'email' => $user->email,
            'isGuest' => $user->isGuest,
        ];

        $chats = $this->chatRepository->findByUser($userId, 20);

        $models = $this->aiService->getAvailableModels();

        // Find first available model as default
        $defaultModel = 'claude-3-5-sonnet-20241022';
        foreach ($models as $modelId => $modelInfo) {
            if ($modelInfo['available']) {
                $defaultModel = $modelId;

                break;
            }
        }

        $html = $this->renderer->render('layout::default', [
            'title' => 'AI Chatbot',
            'user' => $userInfo,
            'defaultModel' => $defaultModel,
            'content' => $this->renderer->render('app::home', [
                'chats' => $chats,
                'user' => $userInfo,
                'models' => $models,
                'defaultModel' => $defaultModel,
            ]),
        ]);

        return new HtmlResponse($html);
    }
}
