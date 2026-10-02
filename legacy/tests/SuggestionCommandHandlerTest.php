<?php

declare(strict_types=1);

use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\SuggestionsUpdatedEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\EventBus\EventBusInterface;
use App\Infrastructure\Http\Handler\Command\SuggestionCommandHandler;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use App\Infrastructure\Persistence\SqliteSuggestionRepository;
use Laminas\Diactoros\ServerRequest;

beforeEach(function (): void {
    $pdo = createTestPdo();
    $pdo->exec("INSERT INTO users (email, password_hash, is_guest, created_at) VALUES ('a@example.com', 'hash', 0, datetime('now')), ('b@example.com', 'hash', 0, datetime('now'))");

    $this->chats = new SqliteChatRepository($pdo);
    $this->documents = new SqliteDocumentRepository($pdo);
    $this->suggestions = new SqliteSuggestionRepository($pdo);
    $this->eventBus = new class implements EventBusInterface {
        /** @var list<array{int, object}> */
        public array $emitted = [];

        public function subscribe(int $userId, callable $callback): string {
            return 'sub';
        }

        public function unsubscribe(string $subscriptionId): void {}

        public function emit(int $userId, object $event): void {
            $this->emitted[] = [$userId, $event];
        }

        public function broadcast(object $event): void {}
    };

    $chat = Chat::create(userId: 1, title: 'Chat');
    $this->chats->save($chat);
    $this->document = Document::text($chat->id, 'Essay', 'Their is a error. Their is a error.');
    $this->documents->save($this->document);

    $this->suggestion = Suggestion::create($this->document->id, 'Their is a error.', 'There is an error.', 'Grammar');
    $this->suggestions->save($this->suggestion);

    $this->handler = new SuggestionCommandHandler($this->suggestions, $this->documents, $this->chats, $this->eventBus);
    $this->request = fn (string $id, int $userId = 1): ServerRequest => new ServerRequest()
        ->withAttribute('id', $id)
        ->withAttribute(AuthMiddleware::ATTR_USER_ID, $userId)
    ;
});

it('accepts a suggestion as a new document version replacing the first occurrence', function (): void {
    $response = $this->handler->accept(($this->request)($this->suggestion->id));

    $document = $this->documents->findWithContent($this->document->id);
    [$userId, $event] = $this->eventBus->emitted[0];

    expect($response->getStatusCode())->toBe(204)
        ->and($document->content)->toBe('There is an error. Their is a error.')
        ->and($document->currentVersion)->toBe(2)
        ->and($this->suggestions->find($this->suggestion->id)->status)->toBe(Suggestion::STATUS_ACCEPTED)
        ->and($userId)->toBe(1)
        ->and($event)->toBeInstanceOf(DocumentUpdatedEvent::class)
        ->and($event->action)->toBe('updated')
        ->and($event->version)->toBe(2)
    ;
});

it('dismisses a suggestion without touching the document', function (): void {
    $response = $this->handler->dismiss(($this->request)($this->suggestion->id));

    [, $event] = $this->eventBus->emitted[0];

    expect($response->getStatusCode())->toBe(204)
        ->and($this->documents->findWithContent($this->document->id)->currentVersion)->toBe(1)
        ->and($this->suggestions->find($this->suggestion->id)->status)->toBe(Suggestion::STATUS_REJECTED)
        ->and($event)->toBeInstanceOf(SuggestionsUpdatedEvent::class)
        ->and($event->action)->toBe(SuggestionsUpdatedEvent::ACTION_DISMISSED)
    ;
});

it('rejects a suggestion whose sentence is gone instead of applying it', function (): void {
    $this->documents->save($this->document->updateContent('Rewritten by hand.'));

    $response = $this->handler->accept(($this->request)($this->suggestion->id));

    expect($response->getStatusCode())->toBe(204)
        ->and($this->documents->findWithContent($this->document->id)->content)->toBe('Rewritten by hand.')
        ->and($this->suggestions->find($this->suggestion->id)->status)->toBe(Suggestion::STATUS_REJECTED)
        ->and($this->eventBus->emitted[0][1])->toBeInstanceOf(SuggestionsUpdatedEvent::class)
    ;
});

it('forbids another user from resolving the suggestion', function (string $action): void {
    $response = $this->handler->{$action}(($this->request)($this->suggestion->id, 2));

    expect($response->getStatusCode())->toBe(403)
        ->and($this->suggestions->find($this->suggestion->id)->isPending())->toBeTrue()
        ->and($this->eventBus->emitted)->toBe([])
    ;
})->with(['accept', 'dismiss']);

it('returns 404 for an unknown suggestion', function (): void {
    expect($this->handler->accept(($this->request)('00000000-0000-0000-0000-000000000000'))->getStatusCode())->toBe(404);
});

it('ignores a second accept of the same suggestion', function (): void {
    $this->handler->accept(($this->request)($this->suggestion->id));
    $this->handler->accept(($this->request)($this->suggestion->id));

    expect($this->documents->findWithContent($this->document->id)->currentVersion)->toBe(2)
        ->and($this->eventBus->emitted)->toHaveCount(1)
    ;
});
