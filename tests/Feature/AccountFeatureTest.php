<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Model\Chat;
use App\Domain\Model\Message;
use Tests\Support\ChatTestApp;

beforeEach(function (): void {
    $this->container = ChatTestApp::container('testing');
    $this->app = ChatTestApp::create($this->container);

    $this->owner = $this->container->users()->createUser('owner@example.com', 'correct-horse');
    $this->chat = Chat::create(userId: $this->owner->id, model: 'claude-haiku-4-5', title: 'Mine');
    $this->container->chats()->save($this->chat);
    $this->answer = Message::assistant($this->chat->id, 'Hello');
    $this->container->messages()->save($this->answer);

    $this->chatPage = fn () => ChatTestApp::browserOf($this->app, $this->owner)->open('/chat/' . $this->chat->id);
    $this->voteUrl = '?message=' . $this->answer->id . '&amp;up=';
});

afterEach(function (): void {
    ChatTestApp::stop($this->app);
});

it('offers votes and visibility to the owner only', function (): void {
    $owner = ($this->chatPage)();
    $owner->action('visibility', ['visibility' => 'public']);
    $visitor = $this->app->open('/chat/' . $this->chat->id);

    expect($owner->html())->toContain('visibility-selector')->toContain($this->voteUrl)
        ->and($visitor->html())->toContain('Hello')->not->toContain('visibility-selector')->not->toContain($this->voteUrl)
        ->and(fn () => $visitor->action('vote', ['message' => $this->answer->id, 'up' => '1']))->toThrow(\RuntimeException::class, 'Action not found: vote')
        ->and(fn () => $visitor->action('visibility', ['visibility' => 'private']))->toThrow(\RuntimeException::class, 'Action not found: visibility')
        ->and($this->container->votes()->findByChat($this->chat->id))->toBe([])
        ->and($this->container->chats()->find($this->chat->id)?->isPublic())->toBeTrue()
    ;
});

it('votes, changes visibility and deletes the open chat for the page user', function (): void {
    $tab = ($this->chatPage)();

    $tab->action('vote', ['message' => $this->answer->id, 'up' => '1']);
    expect($this->container->votes()->findByChatAndUser($this->chat->id, $this->owner->id))->toBe([$this->answer->id => true]);

    $tab->action('visibility', ['visibility' => 'public']);
    expect($this->container->chats()->find($this->chat->id)?->isPublic())->toBeTrue();

    $tab->patches();
    $tab->action('delete-chat', ['id' => $this->chat->id]);
    expect($this->container->chats()->find($this->chat->id))->toBeNull()
        ->and(ChatTestApp::scripts($tab->patches()))->toBe(["window.location.href = '/'"])
    ;
});

it('reloads instead of acting when the session switched account in another tab', function (): void {
    $browser = ChatTestApp::browserOf($this->app, $this->owner);
    $tab = $browser->open('/chat/' . $this->chat->id);
    $other = $browser->open('/');
    $tab->patches();

    $other->action('logout');
    $header = json_encode($tab->patches(), JSON_UNESCAPED_SLASHES);
    $tab->action('vote', ['message' => $this->answer->id, 'up' => '1']);
    $tab->action('delete-chat', ['id' => $this->chat->id]);

    expect($header)->toContain('<span hidden data-init=\"window.location.reload()\"></span>')
        ->and($this->container->votes()->findByChat($this->chat->id))->toBe([])
        ->and($this->container->chats()->find($this->chat->id))->not->toBeNull()
        ->and(ChatTestApp::scripts($tab->patches()))->toBe(['window.location.reload()', 'window.location.reload()'])
    ;
});

it('shows the sign-in error inline and forgets the password', function (): void {
    $tab = $this->app->open('/');
    $guestId = ChatTestApp::sessionUser($this->app, $tab);

    $tab->action('login', signals: ['authEmail' => 'owner@example.com', 'authPassword' => 'wrong-password', 'authLoading' => true]);

    expect($this->container->users()->findById((int) $guestId)?->isGuest)->toBeTrue()
        ->and($tab->signal('authError'))->toBe('Invalid email or password')
        ->and($tab->signal('authLoading'))->toBeFalse()
        ->and($tab->signal('authPassword'))->toBe('')
        ->and(ChatTestApp::sessionUser($this->app, $tab))->toBe($guestId)
    ;
});

it('signs in, then out', function (): void {
    $tab = $this->app->open('/');
    $tab->patches();

    $tab->action('login', signals: ['authEmail' => ' owner@example.com ', 'authPassword' => 'correct-horse']);
    expect(ChatTestApp::sessionUser($this->app, $tab))->toBe($this->owner->id)
        ->and($tab->signal('authPassword'))->toBe('')
        ->and(ChatTestApp::scripts($tab->patches()))->toBe(['window.location.reload()'])
    ;

    $signedIn = $tab->open('/');
    expect($signedIn->html())->toContain('owner@example.com');

    $signedIn->patches();
    $signedIn->action('logout');
    expect(ChatTestApp::sessionUser($this->app, $signedIn))->toBeNull()
        ->and(ChatTestApp::scripts($signedIn->patches()))->toBe(['window.location.reload()'])
    ;
});

it('upgrades a guest and keeps the guest chats', function (): void {
    $tab = $this->app->open('/');
    $guestId = (int) ChatTestApp::sessionUser($this->app, $tab);
    $this->container->chats()->save(Chat::create(userId: $guestId, model: 'claude-haiku-4-5'));

    $tab->action('upgrade', signals: ['authEmail' => 'new@example.com', 'authPassword' => 'short']);
    expect($tab->signal('authError'))->toBe('Password must be at least 8 characters');

    $tab->patches();
    $tab->action('upgrade', signals: ['authEmail' => 'new@example.com', 'authPassword' => 'long-enough']);
    $user = $this->container->users()->findById($guestId);

    expect($user?->isGuest)->toBeFalse()
        ->and($user?->email)->toBe('new@example.com')
        ->and($this->container->chats()->findByUser($guestId))->toHaveCount(1)
        ->and(ChatTestApp::sessionUser($this->app, $tab))->toBe($guestId)
        ->and(ChatTestApp::scripts($tab->patches()))->toBe(['window.location.reload()'])
    ;
});
