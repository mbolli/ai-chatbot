<?php

declare(strict_types=1);

namespace App\Web;

use App\Container;
use App\Domain\Event\AccountChangedEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\User;
use App\Infrastructure\Auth\SessionInterface;
use App\Infrastructure\Template\TemplateRenderer;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;

/**
 * Accounts (sign in, register, upgrade, sign out), votes, chat visibility and chat deletion.
 *
 * Pages capture their user when they load. Every action checks that the session still belongs to
 * that user, and the header reloads tabs whose session switched account in another tab.
 */
final class AccountFeature {
    private const string RELOAD = 'window.location.reload()';

    public function __construct(private readonly Container $container) {}

    /**
     * @return array{actions: array<string, string>, slots: array<string, callable(): string>}
     */
    public function register(Context $c, ?User $user, ?Chat $chat): array {
        $actions = [
            'deleteChat' => $c->action(function (Context $ctx) use ($user, $chat): void {
                if ($this->guard($ctx, $user) || $user === null) {
                    return;
                }
                $chatId = $this->query($ctx, 'id');
                $status = $this->container->chatCommands()->delete($user->id, $chatId);
                if ($status === 204 && $chatId === $chat?->id) {
                    $ctx->execScript("window.location.href = '/'");
                }
            }, 'delete-chat')->url(),
            'logout' => $c->action(function (Context $ctx): void {
                $session = new ViaSession($ctx);
                $before = $this->container->auth()->getUser($session);
                $this->container->auth()->logout($session);
                $this->accountChanged($ctx, $before);
            }, 'logout')->url(),
        ];

        if ($chat !== null && $user !== null && $chat->isOwnedBy($user->id)) {
            $actions['vote'] = $c->action(function (Context $ctx) use ($user, $chat): void {
                if ($this->guard($ctx, $user)) {
                    return;
                }
                $this->container->voteCommands()->vote($user->id, $chat->id, $this->query($ctx, 'message'), $this->query($ctx, 'up') === '1');
            }, 'vote')->url();

            $actions['visibility'] = $c->action(function (Context $ctx) use ($user, $chat): void {
                if ($this->guard($ctx, $user)) {
                    return;
                }
                $this->container->chatCommands()->visibility($user->id, $chat->id, $this->query($ctx, 'visibility'));
            }, 'visibility')->url();
        }

        return [
            'actions' => $actions,
            'slots' => [
                'header' => $this->header($c, $user, $chat, $actions['visibility'] ?? null),
                'authModal' => $this->authModal($c),
            ],
        ];
    }

    /**
     * True, after scheduling a reload, when the tab acts for a user its session no longer has,
     * or a tab without a user finds that its session got one in another tab.
     */
    public function guard(Context $ctx, ?User $user): bool {
        if (!$this->sessionChanged($ctx, $user)) {
            return false;
        }
        $ctx->execScript(self::RELOAD);

        return true;
    }

    /**
     * Title and visibility of the open chat, live in every tab of its viewers.
     *
     * @return callable(): string
     */
    private function header(Context $c, ?User $user, ?Chat $chat, ?string $visibilityUrl): callable {
        $chatId = $chat?->id;

        return $c->component(function (Context $cc) use ($c, $user, $chatId, $visibilityUrl): void {
            if ($user !== null) {
                $cc->addScope(Scopes::user($user->id));
            }
            if ($chatId !== null) {
                $cc->addScope(Scopes::chat($chatId));
            }

            $cc->view(function () use ($c, $user, $chatId, $visibilityUrl): string {
                if ($this->sessionChanged($c, $user)) {
                    return '<span hidden data-init="' . self::RELOAD . '"></span>';
                }

                $chat = $chatId !== null ? $this->container->chats()->find($chatId) : null;
                if ($chatId !== null && ($chat === null || $user === null || (!$chat->isOwnedBy($user->id) && !$chat->isPublic()))) {
                    return '<span hidden data-init="window.location.href = \'/\'"></span>';
                }

                return $this->container->renderer()->partial('header', [
                    'title' => $chat->title ?? 'New Chat',
                    'chat' => $chat,
                    'visibilityUrl' => $visibilityUrl,
                    'e' => TemplateRenderer::escape(...),
                ]);
            });
        }, 'header');
    }

    /**
     * @return callable(): string
     */
    private function authModal(Context $c): callable {
        $fields = [
            'email' => $c->signal('', 'authEmail'),
            'password' => $c->signal('', 'authPassword'),
            'error' => $c->signal('', 'authError'),
            'loading' => $c->signal(false, 'authLoading'),
        ];

        $auth = $this->container->auth();
        $urls = [
            'login' => $this->authAction($c, 'login', $auth->login(...), $fields),
            'register' => $this->authAction($c, 'register', $auth->register(...), $fields),
            'upgrade' => $this->authAction($c, 'upgrade', $auth->upgradeGuestAccount(...), $fields),
        ];
        $signals = array_map(static fn (Signal $signal): string => $signal->id(), $fields);

        return fn (): string => $this->container->renderer()->partial('auth-modal', [
            'urls' => $urls,
            'signals' => $signals,
            'e' => TemplateRenderer::escape(...),
        ]);
    }

    /**
     * Runs one of the AuthService sign-in methods with the dialog's email and password.
     *
     * @param \Closure(SessionInterface, string, string): array{success: bool, user?: User, error?: string} $attempt
     * @param array{email: Signal, password: Signal, error: Signal, loading: Signal}                        $fields
     */
    private function authAction(Context $c, string $name, \Closure $attempt, array $fields): string {
        return $c->action(function (Context $ctx) use ($attempt, $fields): void {
            $session = new ViaSession($ctx);
            $before = $this->container->auth()->getUser($session);
            $email = mb_trim($fields['email']->string());
            $password = $fields['password']->string();
            // Datastar posts every signal with every action, so the password must not linger
            $fields['password']->setValue('');

            $result = $email === '' || $password === ''
                ? ['success' => false, 'error' => 'Email and password required']
                : $attempt($session, $email, $password);

            if ($result['success']) {
                $this->accountChanged($ctx, $before);

                return;
            }

            $fields['error']->setValue($result['error'] ?? 'Something went wrong');
            $fields['loading']->setValue(false);
            $ctx->syncSignals();
        }, $name)->url();
    }

    /**
     * Reload this tab; the other tabs of its session follow through the old user's scope.
     */
    private function accountChanged(Context $ctx, ?User $before): void {
        if ($before !== null) {
            $this->container->eventBus()->emit($before->id, new AccountChangedEvent($before->id));
        }
        $ctx->execScript(self::RELOAD);
    }

    private function query(Context $ctx, string $name): string {
        $value = $ctx->input($name);

        return \is_string($value) ? $value : '';
    }

    private function sessionChanged(Context $c, ?User $user): bool {
        $current = $this->container->auth()->getUser(new ViaSession($c));
        if ($user === null) {
            return $current !== null;
        }

        return $current === null || $current->id !== $user->id || $current->isGuest !== $user->isGuest;
    }
}
