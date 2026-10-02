<?php

declare(strict_types=1);

namespace App\Web;

use App\Container;
use App\Domain\Model\Chat;
use App\Domain\Model\User;
use App\Infrastructure\Template\TemplateRenderer;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\Via;

/**
 * Pages, actions and live components of the chat UI.
 *
 * The page itself joins no scope. Only its components do, so a broadcast re-renders just the
 * sidebar, the message list or the reply being streamed, never the whole page.
 */
final class ChatApp {
    public function __construct(
        private readonly Via $app,
        private readonly Container $container,
    ) {}

    public function register(): void {
        $this->app->page('/', fn (Context $c) => $this->page($c, null));
        $this->app->page('/chat/{id}', fn (Context $c, string $id) => $this->page($c, $id));
    }

    private function page(Context $c, ?string $chatId): void {
        $user = $this->container->auth()->getOrCreateUser(new ViaSession($c))['user'];
        $chat = $chatId !== null ? $this->container->chats()->find($chatId) : null;

        if ($chatId !== null && ($chat === null || (!$chat->isOwnedBy($user->id) && !$chat->isPublic()))) {
            $c->view(static fn (): string => '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=/"></head><body></body></html>', cacheUpdates: false);

            return;
        }

        $message = $c->signal('', 'message');
        $model = $c->signal($chat->model ?? $this->container->ai()->getDefaultModel(), 'model');

        $actions = $this->registerActions($c, $user, $chat, $message, $model);
        $slots = $this->registerComponents($c, $user, $chat);

        $c->view(fn (): string => $this->renderPage($c->getId(), $user, $chat, $message, $model, $actions, $slots), cacheUpdates: false);
    }

    /**
     * @return array<string, string> action name => URL
     */
    private function registerActions(Context $c, User $user, ?Chat $chat, Signal $message, Signal $model): array {
        $send = $c->action(function (Context $ctx) use ($user, $chat, $message, $model): void {
            $text = mb_trim($message->string());
            if ($text === '') {
                return;
            }

            $message->setValue('');
            $ctx->syncSignals();

            if ($chat === null) {
                $created = $this->container->chatCommands()->create($user->id, $model->string(), $text);
                $this->container->messageCommands()->generate($user->id, $created->id);
                $ctx->execScript('window.location.href = ' . json_encode('/chat/' . $created->id));

                return;
            }

            $this->container->messageCommands()->send($user->id, $chat->id, $text);
        }, 'send');

        $actions = ['send' => $send->url()];

        if ($chat !== null) {
            $actions['stop'] = $c->action(function () use ($user, $chat): void {
                $this->container->messageCommands()->stop($user->id, $chat->id);
            }, 'stop')->url();

            $actions['model'] = $c->action(function () use ($user, $chat, $model): void {
                $this->container->chatCommands()->model($user->id, $chat->id, $model->string());
            }, 'model')->url();
        }

        return $actions;
    }

    /**
     * @return array<string, callable(): string> slot name => component renderer
     */
    private function registerComponents(Context $c, User $user, ?Chat $chat): array {
        $currentChatId = $chat?->id;

        $slots = [
            'sidebar' => $c->component(function (Context $cc) use ($user, $currentChatId): void {
                $cc->addScope(Scopes::user($user->id));
                $cc->view(fn (): string => $this->renderer()->partial('sidebar', [
                    'chats' => $this->container->chats()->findByUser($user->id, 20),
                    'currentChatId' => $currentChatId,
                    'user' => $this->userInfo($user),
                    'e' => TemplateRenderer::escape(...),
                ]), cacheUpdates: false);
            }, 'sidebar'),
            'toasts' => $c->component(function (Context $cc) use ($user): void {
                $cc->addScope(Scopes::user($user->id));
                $cc->view(fn (): string => $this->renderer()->partial('toast', [
                    'toasts' => $this->container->liveState()->toasts($user->id),
                    'e' => TemplateRenderer::escape(...),
                ]), cacheUpdates: false);
            }, 'toasts'),
        ];

        if ($chat === null) {
            return $slots;
        }

        $stream = $c->component(function (Context $cc) use ($chat): void {
            $cc->addScope(Scopes::stream($chat->id));
            $cc->view(fn (): string => $this->renderStream($chat->id), cacheUpdates: false);
        }, 'stream');

        $slots['messages'] = $c->component(function (Context $cc) use ($user, $chat, $stream): void {
            $cc->addScope(Scopes::chat($chat->id));
            $cc->view(fn (): string => $this->renderMessages($user, $chat->id, $stream), cacheUpdates: false);
        }, 'messages');

        return $slots;
    }

    /**
     * @param array<string, string>             $actions
     * @param array<string, callable(): string> $slots
     */
    private function renderPage(string $contextId, User $user, ?Chat $chat, Signal $message, Signal $model, array $actions, array $slots): string {
        // Reload so title, header and visibility reflect the stored chat
        $chat = $chat !== null ? ($this->container->chats()->find($chat->id) ?? $chat) : null;
        $ai = $this->container->ai();

        $shared = [
            'user' => $this->userInfo($user),
            'chat' => $chat,
            'chatId' => $chat?->id,
            'currentChatId' => $chat?->id,
            'models' => $ai->getAvailableModels(),
            'selectedModel' => $model->string(),
            'actions' => $actions,
            'signals' => ['message' => $message->id(), 'model' => $model->id()],
            'slots' => $slots,
            'contextId' => $contextId,
            'e' => TemplateRenderer::escape(...),
            'md' => TemplateRenderer::md(...),
        ];

        return $this->renderer()->render('layout::default', $shared + [
            'title' => $chat->title ?? 'AI Chatbot',
            'content' => $this->renderer()->render($chat === null ? 'app::home' : 'app::chat', $shared + [
                'title' => $chat->title ?? 'New Chat',
            ]),
        ]);
    }

    /**
     * @param callable(): string $stream
     */
    private function renderMessages(User $user, string $chatId, callable $stream): string {
        $documents = [];
        foreach ($this->container->documents()->findByChat($chatId) as $document) {
            if ($document->messageId !== null) {
                $documents[$document->messageId] = $document;
            }
        }

        $live = $this->container->liveState()->stream($chatId);

        return $this->renderer()->partial('messages', [
            'messages' => $this->container->messages()->findByChat($chatId),
            'messageDocuments' => $documents,
            'votes' => $this->container->votes()->findByChatAndUser($chatId, $user->id),
            'chatId' => $chatId,
            'streamingMessageId' => $live['messageId'] ?? null,
            'stream' => $stream,
            'e' => TemplateRenderer::escape(...),
            'md' => TemplateRenderer::md(...),
        ]);
    }

    private function renderStream(string $chatId): string {
        $live = $this->container->liveState()->stream($chatId);
        if ($live === null) {
            return '';
        }

        return $this->renderer()->partial('message', [
            'id' => $live['messageId'],
            'role' => 'assistant',
            'content' => $live['content'],
            'thinking' => $live['thinking'],
            'chatId' => $chatId,
            'streaming' => true,
            'e' => TemplateRenderer::escape(...),
            'md' => TemplateRenderer::md(...),
        ]);
    }

    /**
     * @return array{id: int, email: null|string, isGuest: bool}
     */
    private function userInfo(User $user): array {
        return ['id' => $user->id, 'email' => $user->email, 'isGuest' => $user->isGuest];
    }

    private function renderer(): TemplateRenderer {
        return $this->container->renderer();
    }
}
