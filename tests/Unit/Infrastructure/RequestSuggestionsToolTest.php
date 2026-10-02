<?php

declare(strict_types=1);

use App\Domain\Event\SuggestionsUpdatedEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Infrastructure\AI\Tools\RequestSuggestionsTool;
use App\Infrastructure\EventBus\EventBusInterface;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use App\Infrastructure\Persistence\SqliteSuggestionRepository;

function recordingEventBus(): EventBusInterface {
    return new class implements EventBusInterface {
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
}

beforeEach(function (): void {
    $pdo = createTestPdo();
    $pdo->exec("INSERT INTO users (email, password_hash, is_guest, created_at) VALUES ('a@example.com', 'hash', 0, datetime('now')), ('b@example.com', 'hash', 0, datetime('now'))");

    $this->chats = new SqliteChatRepository($pdo);
    $this->documents = new SqliteDocumentRepository($pdo);
    $this->suggestions = new SqliteSuggestionRepository($pdo);
    $this->eventBus = recordingEventBus();

    $this->chat = Chat::create(userId: 2, title: 'Mine');
    $this->chats->save($this->chat);

    $this->document = Document::text($this->chat->id, 'Essay', "Their is a error here. The rest is fine.\n\nAnother sentance with a typo.");
    $this->documents->save($this->document);

    $this->prompts = [];
    $this->modelResponse = '';
    $this->makeTool = fn (?string $chatId = null): RequestSuggestionsTool => new RequestSuggestionsTool(
        $this->documents,
        $this->suggestions,
        $this->chats,
        $this->eventBus,
        function (string $system, string $prompt): string {
            $this->prompts[] = $prompt;

            return $this->modelResponse;
        },
        $chatId ?? $this->chat->id,
    );
});

describe('RequestSuggestionsTool', function (): void {
    it('stores suggestions that quote the document and notifies the chat owner', function (): void {
        $this->modelResponse = <<<'JSON'
        ```json
        [
          {"originalSentence": "Their is a error here.", "suggestedSentence": "There is an error here.", "description": "Fix spelling and article"},
          {"originalSentence": "Another sentance with a typo.", "suggestedSentence": "Another sentence with a typo.", "description": "Fix spelling"},
          {"originalSentence": "A sentence the model invented.", "suggestedSentence": "Something else.", "description": "Not in the text"}
        ]
        ```
        JSON;

        $result = ($this->makeTool)()->execute(['documentId' => $this->document->id]);

        $pending = $this->suggestions->findPendingByDocument($this->document->id);

        expect($result)->toBe("Added 2 suggestions to document 'Essay'. The user can accept or dismiss them in the document panel; do not repeat them.")
            ->and($this->prompts)->toBe([$this->document->content])
            ->and(array_map(fn (Suggestion $s): string => $s->suggestedText, $pending))->toBe(['There is an error here.', 'Another sentence with a typo.'])
            ->and($this->eventBus->emitted)->toHaveCount(1)
        ;

        [$userId, $event] = $this->eventBus->emitted[0];
        expect($userId)->toBe(2)
            ->and($event)->toBeInstanceOf(SuggestionsUpdatedEvent::class)
            ->and($event->action)->toBe(SuggestionsUpdatedEvent::ACTION_REQUESTED)
            ->and($event->documentId)->toBe($this->document->id)
        ;
    });

    it('uses the latest text document of the chat when no ID is given', function (): void {
        $this->modelResponse = '[{"originalSentence": "Their is a error here.", "suggestedSentence": "There is an error here.", "description": "Grammar"}]';

        expect(($this->makeTool)()->execute([]))->toStartWith('Added 1 suggestion to')
            ->and($this->suggestions->findPendingByDocument($this->document->id))->toHaveCount(1)
        ;
    });

    it('refuses a document from another chat without calling the model', function (): void {
        $other = Chat::create(userId: 1, title: 'Theirs');
        $this->chats->save($other);
        $foreign = Document::text($other->id, 'Private', 'Secret text.');
        $this->documents->save($foreign);

        $result = ($this->makeTool)()->execute(['documentId' => $foreign->id]);

        expect($result)->toStartWith('Error:')
            ->and($result)->toContain("Documents in this chat: {$this->document->id} (text: Essay)")
            ->and($result)->not->toContain('Private')
            ->and($this->prompts)->toBe([])
            ->and($this->suggestions->findPendingByDocument($foreign->id))->toBe([])
            ->and($this->eventBus->emitted)->toBe([])
        ;
    });

    it('reports a chat without text documents', function (): void {
        $empty = Chat::create(userId: 2, title: 'Empty');
        $this->chats->save($empty);

        expect(($this->makeTool)($empty->id)->execute([]))->toBe('Error: This chat has no text document.');
    });

    it('refuses non-text documents', function (): void {
        $code = Document::code($this->chat->id, 'script.py', 'print(1)');
        $this->documents->save($code);

        expect(($this->makeTool)()->execute(['documentId' => $code->id]))->toContain('only available for text documents')
            ->and($this->prompts)->toBe([])
        ;
    });

    it('returns an error when the model call fails', function (): void {
        $tool = new RequestSuggestionsTool(
            $this->documents,
            $this->suggestions,
            $this->chats,
            $this->eventBus,
            fn (string $system, string $prompt): string => throw new RuntimeException('overloaded'),
            $this->chat->id,
        );

        expect($tool->execute(['documentId' => $this->document->id]))->toBe('Error: Could not generate suggestions.')
            ->and($this->eventBus->emitted)->toBe([])
        ;
    });

    it('replaces earlier pending suggestions on a new request', function (): void {
        $this->modelResponse = '[{"originalSentence": "Their is a error here.", "suggestedSentence": "There is an error here.", "description": "Grammar"}]';
        ($this->makeTool)()->execute([]);

        $this->modelResponse = 'No changes needed.';
        $result = ($this->makeTool)()->execute([]);

        expect($result)->toBe("No suggestions for document 'Essay': the text needs no changes.")
            ->and($this->suggestions->findPendingByDocument($this->document->id))->toBe([])
        ;
    });

    it('caps the document text sent to the model', function (): void {
        $long = Document::text($this->chat->id, 'Long', str_repeat('ä', RequestSuggestionsTool::MAX_CONTENT_CHARS + 100));
        $this->documents->save($long);

        ($this->makeTool)()->execute(['documentId' => $long->id]);

        expect(mb_strlen($this->prompts[0]))->toBe(RequestSuggestionsTool::MAX_CONTENT_CHARS);
    });
});

describe('RequestSuggestionsTool::parseSuggestions', function (): void {
    it('parses a plain JSON array', function (): void {
        $items = RequestSuggestionsTool::parseSuggestions('[{"originalSentence": "a b.", "suggestedSentence": "A b.", "description": "Capitalize"}]');

        expect($items)->toBe([['originalSentence' => 'a b.', 'suggestedSentence' => 'A b.', 'description' => 'Capitalize']]);
    });

    it('ignores code fences and surrounding prose with braces and quotes', function (): void {
        $response = "Here's what I'd change {roughly}:\n```json\n[{\"originalSentence\": \"x {y} \\\"z\\\".\", \"suggestedSentence\": \"X.\", \"description\": \"d\"}]\n```\nHope it \"helps\".";

        expect(RequestSuggestionsTool::parseSuggestions($response))->toBe([
            ['originalSentence' => 'x {y} "z".', 'suggestedSentence' => 'X.', 'description' => 'd'],
        ]);
    });

    it('unwraps a suggestions object', function (): void {
        $items = RequestSuggestionsTool::parseSuggestions('{"suggestions": [{"originalSentence": "a.", "suggestedSentence": "b.", "description": "c"}]}');

        expect($items)->toHaveCount(1)->and($items[0]['suggestedSentence'])->toBe('b.');
    });

    it('keeps complete objects of a response cut off by the token limit', function (): void {
        $items = RequestSuggestionsTool::parseSuggestions('[{"originalSentence": "a.", "suggestedSentence": "b.", "description": "c"}, {"originalSentence": "d.", "suggestedSent');

        expect($items)->toHaveCount(1);
    });

    it('drops incomplete, unchanged and duplicate items', function (): void {
        $items = RequestSuggestionsTool::parseSuggestions(json_encode([
            ['originalSentence' => 'a.', 'suggestedSentence' => 'b.'],
            ['originalSentence' => 'same.', 'suggestedSentence' => 'same.', 'description' => 'noop'],
            ['originalSentence' => 'a.', 'suggestedSentence' => 'b.', 'description' => 'kept'],
            ['originalSentence' => 'a.', 'suggestedSentence' => 'c.', 'description' => 'duplicate'],
            ['originalSentence' => 42, 'suggestedSentence' => 'n.', 'description' => 'not a string'],
        ]));

        expect($items)->toBe([['originalSentence' => 'a.', 'suggestedSentence' => 'b.', 'description' => 'kept']]);
    });

    it('returns at most five suggestions', function (): void {
        $all = array_map(fn (int $i): array => ['originalSentence' => "s{$i}.", 'suggestedSentence' => "S{$i}.", 'description' => 'd'], range(1, 8));

        expect(RequestSuggestionsTool::parseSuggestions(json_encode($all)))->toHaveCount(RequestSuggestionsTool::MAX_SUGGESTIONS);
    });

    it('returns nothing for a response without JSON', function (): void {
        expect(RequestSuggestionsTool::parseSuggestions('I cannot help with that.'))->toBe([])
            ->and(RequestSuggestionsTool::parseSuggestions(''))->toBe([])
        ;
    });
});
