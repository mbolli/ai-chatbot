<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Container;
use App\Domain\Model\Chat;
use App\Domain\Model\Message;
use App\Domain\Model\User;
use App\Web\AccountFeature;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

// php-via queues patches in an array instead of an OpenSwoole channel
putenv('VIA_TEST_MODE=1');

beforeEach(function (): void {
    $this->container = new Container(['database' => ['path' => ':memory:'], 'app' => ['env' => 'testing']], \dirname(__DIR__, 2));
    $this->container->pdo()->exec((string) file_get_contents(__DIR__ . '/../../data/schema.sql'));
    $this->via = new Via((new Config())->withLogLevel('error'));

    $this->owner = $this->container->users()->createUser('owner@example.com', 'correct-horse');
    $this->chat = Chat::create(userId: $this->owner->id, model: 'claude-haiku-4-5', title: 'Mine');
    $this->container->chats()->save($this->chat);
    $this->answer = Message::assistant($this->chat->id, 'Hello');
    $this->container->messages()->save($this->answer);

    $this->session = 'session-' . bin2hex(random_bytes(4));
    $this->signIn = fn (User $user) => $this->via->setSessionData($this->session, 'authenticated_user', $user->id);
    $this->page = function (User $user, ?Chat $chat): array {
        $context = new Context('ctx-' . bin2hex(random_bytes(4)), '/chat/{id}', $this->via, null, $this->session);

        return [$context, (new AccountFeature($this->container))->register($context, $user, $chat)];
    };
    $this->run = function (Context $context, string $action, array $query = [], array $signals = []): void {
        $context->setRequestInput($query, []);
        foreach ($signals as $name => $value) {
            $context->getSignal($name)?->setValue($value);
            $context->getSignal($name)?->markSynced();
        }
        $context->executeAction($action);
    };
    $this->scripts = function (Context $context): array {
        $scripts = [];
        while (($patch = $context->getPatch()) !== null) {
            if ($patch['type'] === 'script') {
                $scripts[] = $patch['content'];
            }
        }

        return $scripts;
    };
});

it('offers votes and visibility to the owner only', function (): void {
    ($this->signIn)($this->owner);
    [, $owner] = ($this->page)($this->owner, $this->chat);
    $guest = $this->container->users()->createGuestUser();
    [, $visitor] = ($this->page)($guest, $this->chat);

    expect(array_keys($owner['actions']))->toContain('vote', 'visibility', 'deleteChat', 'logout')
        ->and(array_keys($visitor['actions']))->not->toContain('vote', 'visibility')
    ;
});

it('votes, changes visibility and deletes the open chat for the page user', function (): void {
    ($this->signIn)($this->owner);
    [$context] = ($this->page)($this->owner, $this->chat);

    ($this->run)($context, 'vote', ['message' => $this->answer->id, 'up' => '1']);
    expect($this->container->votes()->findByChatAndUser($this->chat->id, $this->owner->id))->toBe([$this->answer->id => true]);

    ($this->run)($context, 'visibility', ['visibility' => 'public']);
    expect($this->container->chats()->find($this->chat->id)?->isPublic())->toBeTrue();

    ($this->run)($context, 'delete-chat', ['id' => $this->chat->id]);
    expect($this->container->chats()->find($this->chat->id))->toBeNull()
        ->and(($this->scripts)($context))->toBe(["window.location.href = '/'"])
    ;
});

it('reloads instead of acting when the session switched account in another tab', function (): void {
    ($this->signIn)($this->owner);
    [$context, $feature] = ($this->page)($this->owner, $this->chat);
    $this->via->setSessionData($this->session, 'authenticated_user', null);

    ($this->run)($context, 'vote', ['message' => $this->answer->id, 'up' => '1']);
    ($this->run)($context, 'delete-chat', ['id' => $this->chat->id]);

    expect($this->container->votes()->findByChat($this->chat->id))->toBe([])
        ->and($this->container->chats()->find($this->chat->id))->not->toBeNull()
        ->and(($this->scripts)($context))->toBe(['window.location.reload()', 'window.location.reload()'])
        ->and($feature['slots']['header']())->toContain('data-init="window.location.reload()"')
    ;
});

it('shows the sign-in error inline and forgets the password', function (): void {
    $guest = $this->container->users()->createGuestUser();
    ($this->signIn)($guest);
    [$context] = ($this->page)($guest, null);

    ($this->run)($context, 'login', signals: ['authEmail' => 'owner@example.com', 'authPassword' => 'wrong-password', 'authLoading' => true]);

    expect($context->getSignal('authError')?->string())->toBe('Invalid email or password')
        ->and($context->getSignal('authLoading')?->bool())->toBeFalse()
        ->and($context->getSignal('authPassword')?->string())->toBe('')
        ->and($this->via->getSessionData($this->session, 'authenticated_user'))->toBe($guest->id)
    ;
});

it('signs in, then out', function (): void {
    $guest = $this->container->users()->createGuestUser();
    ($this->signIn)($guest);
    [$context] = ($this->page)($guest, null);

    ($this->run)($context, 'login', signals: ['authEmail' => ' owner@example.com ', 'authPassword' => 'correct-horse']);
    expect($this->via->getSessionData($this->session, 'authenticated_user'))->toBe($this->owner->id)
        ->and(($this->scripts)($context))->toBe(['window.location.reload()'])
    ;

    [$signedIn] = ($this->page)($this->owner, null);
    ($this->run)($signedIn, 'logout');
    expect($this->via->getSessionData($this->session, 'authenticated_user'))->toBeNull()
        ->and(($this->scripts)($signedIn))->toBe(['window.location.reload()'])
    ;
});

it('upgrades a guest and keeps the guest chats', function (): void {
    $guest = $this->container->users()->createGuestUser();
    $this->container->chats()->save(Chat::create(userId: $guest->id, model: 'claude-haiku-4-5'));
    ($this->signIn)($guest);
    [$context] = ($this->page)($guest, null);

    ($this->run)($context, 'upgrade', signals: ['authEmail' => 'new@example.com', 'authPassword' => 'short']);
    expect($context->getSignal('authError')?->string())->toBe('Password must be at least 8 characters');

    ($this->run)($context, 'upgrade', signals: ['authEmail' => 'new@example.com', 'authPassword' => 'long-enough']);
    $user = $this->container->users()->findById($guest->id);

    expect($user?->isGuest)->toBeFalse()
        ->and($user?->email)->toBe('new@example.com')
        ->and($this->container->chats()->findByUser($guest->id))->toHaveCount(1)
    ;
});
