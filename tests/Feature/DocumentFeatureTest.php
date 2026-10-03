<?php

declare(strict_types=1);

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\SuggestionsUpdatedEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Document;
use App\Domain\Model\Message;
use App\Domain\Model\Suggestion;
use App\Domain\Model\User;
use Tests\Support\ChatTestApp;
use Tests\Support\DocumentTab;

beforeEach(function (): void {
    $this->container = ChatTestApp::container('development');
    $this->container->pdo()->exec("INSERT INTO users (email, password_hash, is_guest, created_at) VALUES ('a@example.com', 'hash', 0, datetime('now')), ('b@example.com', 'hash', 0, datetime('now'))");
    $this->app = ChatTestApp::create($this->container);

    $this->owner = new User(1, 'a@example.com', 'hash');
    $this->visitor = new User(2, 'b@example.com', 'hash');
    $this->chat = Chat::create(userId: 1, visibility: 'public');
    $this->container->chats()->save($this->chat);

    $this->code = Document::code($this->chat->id, 'Script', 'print("v1")');
    $this->container->documents()->save($this->code);
    $this->container->documents()->save($this->code->updateContent('print("v2")'));
    $this->essay = Document::text($this->chat->id, 'Essay', 'Their is a error. Fine.');
    $this->container->documents()->save($this->essay);

    // Each tab in a browser of its own, signed in as $user
    $this->tab = fn (?User $user = null): DocumentTab => new DocumentTab(
        ChatTestApp::browserOf($this->app, $user ?? $this->owner)->open('/chat/' . $this->chat->id),
    );
});

afterEach(function (): void {
    ChatTestApp::stop($this->app);
});

it('starts closed, showing no document', function (): void {
    $tab = ($this->tab)();

    expect($tab->title())->toBe('Artifact')
        ->and($tab->command())->toBe('artifact-command-0: $_artifactOpen = false; $_artifactEditing = false')
    ;
});

it('opens the document of a reply from the button under it', function (): void {
    $reply = Message::assistant($this->chat->id, 'Here is a table');
    $this->container->messages()->save($reply);
    $table = Document::sheet($this->chat->id, 'Table', 'a,b', $reply->id);
    $this->container->documents()->save($table);
    $tab = ($this->tab)();

    $found = preg_match('#id="artifact-btn-' . $reply->id . '"[^>]*@post\(\'/_action/([^?]+)\?id=' . $table->id . '\'\)#', $tab->tab->html(), $button);
    $tab->tab->action($button[1] ?? 'missing', ['id' => $table->id]);

    expect($found)->toBe(1)
        ->and($button[1])->toBe('artifact-open')
        ->and($tab->title())->toBe('Table')
    ;
});

it('opens a document of the chat in this tab only', function (): void {
    $tab = ($this->tab)();
    $other = ($this->tab)();

    $tab->act('open', ['id' => $this->code->id]);

    expect($tab->title())->toBe('Script')
        ->and($tab->render())->toContain('print(&quot;v2&quot;)')
        ->and($tab->command())->toStartWith('artifact-command-1: $_artifactOpen = true')
        ->and($other->updates())->toBe(0)
        ->and($other->title())->toBe('Artifact')
    ;
});

it('keeps the open document through a broadcast re-render', function (): void {
    $tab = ($this->tab)();
    $tab->act('open', ['id' => $this->essay->id]);
    $command = $tab->command();
    $tab->updates();

    $this->container->eventBus()->emit(1, new DocumentUpdatedEvent($this->code->id, $this->chat->id, 1, 'updated'));

    expect($tab->updates())->toBe(1)
        ->and($tab->title())->toBe('Essay')
        ->and($tab->command())->toBe($command)
    ;
});

it('ignores a document of another chat', function (): void {
    $otherChat = Chat::create(userId: 1);
    $this->container->chats()->save($otherChat);
    $foreign = Document::text($otherChat->id, 'Foreign', 'Secret');
    $this->container->documents()->save($foreign);
    $tab = ($this->tab)();

    $tab->act('open', ['id' => $foreign->id]);

    expect($tab->updates())->toBe(0)
        ->and($tab->title())->toBe('Artifact')
        ->and($tab->tab->html())->not->toContain('Secret')
    ;
});

it('switches versions and back to the latest', function (): void {
    $tab = ($this->tab)();
    $tab->act('open', ['id' => $this->code->id]);

    $tab->act('version', ['version' => '1']);
    $old = $tab->render();
    $tab->act('version', ['version' => '2']);

    expect($old)->toContain('print(&quot;v1&quot;)')->toContain('Viewing version 1, not the latest')
        ->and($tab->render())->toContain('print(&quot;v2&quot;)')->not->toContain('artifact-version-note')
    ;
});

it('saves the edited content as a new version and shows the latest', function (): void {
    $tab = ($this->tab)();
    $tab->act('open', ['id' => $this->code->id]);
    $tab->act('version', ['version' => '1']);

    $tab->edit('print("v3")');
    $tab->act('save');

    // TestApp renders the save's broadcast inside the action, before it switches to the latest version, where a
    // server renders it on the next tick, so the page is rendered from the tab's state on the server instead
    expect($this->container->documents()->findWithContent($this->code->id)->content)->toBe('print("v3")')
        ->and($tab->tab->signal('artifact.content'))->toBe('')
        ->and($tab->tab->signal('artifact.version'))->toBe(0)
        ->and($tab->tab->html())->toContain('print(&quot;v3&quot;)')->toContain('value="3" selected')
    ;
});

it('closes the panel of every tab showing a deleted document', function (): void {
    $tab = ($this->tab)();
    $other = ($this->tab)();
    $tab->act('open', ['id' => $this->code->id]);
    $other->act('open', ['id' => $this->code->id]);

    $tab->act('delete');

    expect($this->container->documents()->find($this->code->id))->toBeNull()
        ->and($other->title())->toBe('Artifact')
        ->and($other->command())->toBe('artifact-command-2: $_artifactOpen = false; $_artifactEditing = false')
    ;
});

it('shows a public chat read-only to other users', function (): void {
    $visitor = ($this->tab)($this->visitor);
    $visitor->act('open', ['id' => $this->code->id]);
    $before = $this->container->documents()->findWithContent($this->code->id)->content;

    $visitor->edit('print("hacked")');
    $visitor->act('save');
    $visitor->act('delete');

    expect($visitor->title())->toBe('Script')
        ->and($visitor->render())->not->toContain('Edit Code')->not->toContain('Delete artifact')
        ->and($this->container->documents()->findWithContent($this->code->id)->content)->toBe($before)
    ;
});

it('opens a document the reply created in the owner\'s tabs, once', function (): void {
    $tab = ($this->tab)();
    $visitor = ($this->tab)($this->visitor);

    $this->container->eventBus()->emit(1, new DocumentUpdatedEvent($this->essay->id, $this->chat->id, 1, 'created'));
    $opened = $tab->command();
    $tab->act('open', ['id' => $this->code->id]);

    expect($opened)->toStartWith('artifact-command-1: $_artifactOpen = true')
        ->and($tab->title())->toBe('Script')
        ->and($visitor->updates())->toBe(1)
        ->and($visitor->title())->toBe('Artifact')
    ;
});

it('opens a document the reply updates', function (): void {
    $tab = ($this->tab)();
    $tab->act('open', ['id' => $this->code->id]);

    $this->container->eventBus()->emit(1, new ChatUpdatedEvent($this->chat->id, 1, 'assistant_started', messageId: 'reply'));
    $this->container->eventBus()->emit(1, new DocumentUpdatedEvent($this->essay->id, $this->chat->id, 1, 'updated'));

    expect($tab->title())->toBe('Essay');
});

it('leaves the tabs alone when the owner saves an edit', function (): void {
    $tab = ($this->tab)();
    $editor = ($this->tab)();
    $tab->act('open', ['id' => $this->code->id]);
    $editor->act('open', ['id' => $this->essay->id]);
    $tab->updates();

    $editor->edit('Edited.');
    $editor->act('save');

    expect($tab->updates())->toBe(1)
        ->and($tab->title())->toBe('Script')
    ;
});

it('opens a document the reply asked suggestions for and accepts them', function (): void {
    $suggestion = Suggestion::create($this->essay->id, 'Their is a error.', 'There is an error.', 'Grammar');
    $this->container->suggestions()->save($suggestion);
    $tab = ($this->tab)();

    $this->container->eventBus()->emit(1, new SuggestionsUpdatedEvent($this->essay->id, $this->chat->id, 1, SuggestionsUpdatedEvent::ACTION_REQUESTED));
    $html = $tab->render();
    $tab->act('accept', ['id' => $suggestion->id]);

    expect($tab->title())->toBe('Essay')
        ->and($html)->toContain('1 suggestion<')->toContain('/_action/artifact-accept?id=' . $suggestion->id)
        ->and($this->container->documents()->findWithContent($this->essay->id)->content)->toBe('There is an error. Fine.')
        ->and($tab->render())->not->toContain('artifact-suggestions')
    ;
});

it('dismisses a suggestion and hides suggestions from visitors', function (): void {
    $suggestion = Suggestion::create($this->essay->id, 'Their is a error.', 'There is an error.', 'Grammar');
    $this->container->suggestions()->save($suggestion);
    $tab = ($this->tab)();
    $visitor = ($this->tab)($this->visitor);
    $tab->act('open', ['id' => $this->essay->id]);
    $visitor->act('open', ['id' => $this->essay->id]);

    $ownerHtml = $tab->render();
    $visitorHtml = $visitor->render();
    $tab->act('dismiss', ['id' => $suggestion->id]);

    expect($ownerHtml)->toContain('artifact-suggestions')
        ->and($visitorHtml)->toContain('Their is a error.')->not->toContain('artifact-suggestions')
        ->and($tab->render())->not->toContain('artifact-suggestions')
        ->and($this->container->suggestions()->find($suggestion->id)->status)->toBe(Suggestion::STATUS_REJECTED)
    ;
});
