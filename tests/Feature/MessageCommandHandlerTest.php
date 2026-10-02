<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\MessageStreamingEvent;
use App\Domain\Event\RateLimitExceededEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Message;
use App\Domain\Model\MessageUsage;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Domain\Service\RateLimitService;
use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\StreamEnd;
use App\Domain\Service\Stream\TextDelta;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;
use App\Domain\Service\Stream\Usage;
use App\Infrastructure\AI\StreamingSessionManager;
use App\Infrastructure\AI\Tools\CreateDocumentTool;
use App\Infrastructure\AI\Tools\UpdateDocumentTool;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\EventBus\EventBusInterface;
use App\Infrastructure\Http\Handler\Command\MessageCommandHandler;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use App\Infrastructure\Persistence\SqliteMessageRepository;
use App\Infrastructure\Persistence\SqliteRateLimitRepository;
use App\Infrastructure\Repository\SqliteUserRepository;
use Laminas\Diactoros\ServerRequest;

/**
 * Plays back scripted turns. Tool steps run the real document tools, like the streaming clients do.
 */
final class ScriptedAIService implements AIServiceInterface {
    /**
     * @var list<list<mixed>>
     */
    public array $turns = [];

    /**
     * @var list<list<array{role: string, content: string}>>
     */
    public array $histories = [];

    public function __construct(private readonly DocumentRepositoryInterface $documents) {}

    public function streamChat(array $messages, string $model, ?string $chatId = null, ?string $messageId = null): \Generator {
        $this->histories[] = $messages;
        $create = new CreateDocumentTool($this->documents);
        $create->setChatContext((string) $chatId, $messageId);
        $tools = ['createDocument' => $create, 'updateDocument' => new UpdateDocumentTool($this->documents, $chatId)];

        foreach (array_shift($this->turns) ?? [] as $step) {
            if ($step instanceof \Closure) {
                $step = $step($messages);
            }
            if (\is_array($step)) {
                [$name, $input] = $step;
                $id = 'call_' . bin2hex(random_bytes(4));

                yield new ToolCall($id, $name, $input);
                $result = $tools[$name]->execute($input);

                yield new ToolResult($id, $name, $result, str_starts_with($result, 'Error:'));

                continue;
            }
            if ($step !== null) {
                yield $step;
            }
        }
    }

    public function generateTitle(string $firstMessage): string {
        return 'Title';
    }

    public function getAvailableModels(): array {
        return ['claude-haiku-4-5' => ['name' => 'Claude Haiku 4.5', 'provider' => 'anthropic', 'available' => true]];
    }

    public function getDefaultModel(): string {
        return 'claude-haiku-4-5';
    }
}

final class RecordingEventBus implements EventBusInterface {
    /**
     * @var list<object>
     */
    public array $events = [];

    public function subscribe(int $userId, callable $callback): string {
        return 'sub';
    }

    public function unsubscribe(string $subscriptionId): void {}

    public function emit(int $userId, object $event): void {
        $this->events[] = $event;
    }

    public function broadcast(object $event): void {}

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function ofType(string $class): array {
        return array_values(array_filter($this->events, fn (object $e): bool => $e instanceof $class));
    }
}

beforeEach(function (): void {
    $this->pdo = createTestPdo();
    $this->pdo->exec("INSERT INTO users (id, email, is_guest) VALUES (1, 'user@example.com', 0)");

    $this->chats = new SqliteChatRepository($this->pdo);
    $this->messages = new SqliteMessageRepository($this->pdo);
    $this->documents = new SqliteDocumentRepository($this->pdo);
    $this->rateLimits = new SqliteRateLimitRepository($this->pdo);
    $this->ai = new ScriptedAIService($this->documents);
    $this->bus = new RecordingEventBus();
    $this->sessions = new StreamingSessionManager();

    $this->chat = Chat::create(userId: 1, model: 'claude-haiku-4-5', title: 'Existing');
    $this->chats->save($this->chat);

    $this->limits = [];
    $this->contextMaxTokens = 8000;
    $this->handler = fn (): MessageCommandHandler => new MessageCommandHandler(
        $this->chats,
        $this->messages,
        $this->documents,
        $this->bus,
        $this->ai,
        $this->sessions,
        new RateLimitService(
            $this->rateLimits,
            new SqliteUserRepository($this->pdo),
            registeredDailyLimit: $this->limits['daily'] ?? 100,
            registeredHourlyLimit: $this->limits['hourly'] ?? 0,
            registeredDailyTokenLimit: $this->limits['tokens'] ?? 0,
        ),
        contextMaxTokens: $this->contextMaxTokens,
    );

    $this->send = function (string $text, string $method = 'send'): int {
        $request = (new ServerRequest(serverParams: ['REMOTE_ADDR' => '203.0.113.5'], uri: 'https://chat.example.com/cmd', method: 'POST'))
            ->withAttribute('chatId', $this->chat->id)
            ->withAttribute(AuthMiddleware::ATTR_USER_ID, 1)
            ->withParsedBody(['message' => $text])
        ;
        $status = 0;
        \Swoole\Coroutine\run(function () use ($request, $method, &$status): void {
            $status = ($this->handler)()->{$method}($request)->getStatusCode();
        });

        return $status;
    };

    $this->lastAssistant = function (): Message {
        $assistant = array_values(array_filter($this->messages->findByChat($this->chat->id), fn (Message $m): bool => $m->isAssistant()));

        return end($assistant);
    };

    $this->logFile = tempnam(sys_get_temp_dir(), 'ai-log');
    $this->previousLog = ini_set('error_log', $this->logFile);
});

afterEach(function (): void {
    ini_set('error_log', (string) $this->previousLog);
    @unlink($this->logFile);
});

it('replays tool calls in the next turn so the model can update an earlier document', function (): void {
    $this->ai->turns[] = [
        new TextDelta('Here is your script.'),
        ['createDocument', ['kind' => 'code', 'title' => 'Fib', 'language' => 'python', 'content' => str_repeat('print(1)' . "\n", 200)]],
        new StreamEnd(StopReason::EndTurn),
    ];
    // Second turn: act like a model that reads the document ID from the replayed history
    $this->ai->turns[] = [
        function (array $history): array {
            preg_match('/with ID: ([0-9a-f-]{36})/', $history[1]['content'], $match);

            return ['updateDocument', ['documentId' => $match[1] ?? 'missing', 'content' => 'print(2)']];
        },
        new TextDelta('Updated.'),
        new StreamEnd(StopReason::EndTurn),
    ];

    expect(($this->send)('Write a fibonacci script'))->toBe(204);
    $first = ($this->lastAssistant)();
    $document = $this->documents->findByMessageId($first->id);

    expect($first->content)->toBe('Here is your script.')
        ->and(array_column($first->parts, 'type'))->toBe(['text', 'tool_call', 'tool_result'])
        ->and(mb_strlen($first->parts[1]['input']['content']))->toBeLessThan(600)
        ->and($first->parts[2]['content'])->toBe("Document created successfully with ID: {$document->id}")
    ;

    expect(($this->send)('Now make it print 2'))->toBe(204);

    $replayed = $this->ai->histories[1][1];
    expect($replayed['role'])->toBe('assistant')
        ->and($replayed['content'])->toBe(
            "Here is your script.\n\n[createDocument {\"kind\":\"code\",\"title\":\"Fib\",\"language\":\"python\"} → Document created successfully with ID: {$document->id}]",
        )
    ;

    $updated = $this->documents->findWithContent($document->id);
    expect($updated->currentVersion)->toBe(2)
        ->and($updated->content)->toBe('print(2)')
    ;

    $refresh = array_values(array_filter($this->bus->ofType(DocumentUpdatedEvent::class), fn ($e): bool => $e->action === 'updated'));
    expect($refresh)->toHaveCount(1)
        ->and($refresh[0]->documentId)->toBe($document->id)
        ->and($refresh[0]->version)->toBe(2)
    ;
});

it('appends a notice when the response hit the length limit and keeps it out of the history', function (): void {
    $this->ai->turns[] = [new TextDelta('Partial answer'), new StreamEnd(StopReason::MaxTokens)];
    $this->ai->turns[] = [new TextDelta('ok'), new StreamEnd(StopReason::EndTurn)];

    ($this->send)('Explain everything');
    $message = ($this->lastAssistant)();

    expect($message->content)->toBe("Partial answer\n\n⚠️ *Response cut off at the length limit.*")
        ->and(end($message->parts))->toMatchArray(['type' => 'notice', 'kind' => 'max_tokens'])
    ;

    $complete = array_values(array_filter($this->bus->ofType(MessageStreamingEvent::class), fn ($e): bool => $e->isComplete));
    expect($complete[0]->fullContent)->toBe($message->content);

    ($this->send)('Continue');
    expect($this->ai->histories[1][1]['content'])->toBe('Partial answer');
});

it('shows a refusal notice instead of the empty-response warning', function (): void {
    $this->ai->turns[] = [new StreamEnd(StopReason::Refusal)];

    ($this->send)('Something disallowed');

    expect(($this->lastAssistant)()->content)->toBe('⚠️ *The model declined to answer this request.*');
});

it('shows a notice when the tool step limit was reached', function (): void {
    $this->ai->turns[] = [new TextDelta('Working'), new StreamEnd(StopReason::ToolLimit)];

    ($this->send)('Loop forever');

    expect(($this->lastAssistant)()->content)->toContain('tool step limit');
});

it('does not treat a tool-only response as empty', function (): void {
    $this->ai->turns[] = [
        ['createDocument', ['kind' => 'text', 'title' => 'Notes', 'content' => 'a']],
        new StreamEnd(StopReason::EndTurn),
    ];
    ($this->send)('Make notes');
    $documentId = $this->documents->findByMessageId(($this->lastAssistant)()->id)->id;

    $this->ai->turns[] = [['updateDocument', ['documentId' => $documentId, 'content' => 'b']], new StreamEnd(StopReason::EndTurn)];
    ($this->send)('Change it');

    $message = ($this->lastAssistant)();
    expect($message->content)->toBe('')
        ->and(array_column($message->parts, 'type'))->toBe(['tool_call', 'tool_result'])
    ;
});

it('warns about an empty response', function (): void {
    $this->ai->turns[] = [new StreamEnd(StopReason::EndTurn)];

    ($this->send)('Hello?');

    expect(($this->lastAssistant)()->content)->toStartWith('⚠️ The AI returned an empty response.');
});

it('persists usage, adds it to the daily tally and logs one telemetry line', function (): void {
    $this->ai->turns[] = [
        new TextDelta('Hi there'),
        ['createDocument', ['kind' => 'text', 'title' => 'Doc', 'content' => 'secret document body']],
        new StreamEnd(StopReason::EndTurn, new Usage(inputTokens: 1200, outputTokens: 80, cacheReadTokens: 3000, cacheWriteTokens: 500)),
    ];

    ($this->send)('My private question');
    $message = ($this->lastAssistant)();
    $usage = $this->messages->findUsage($message->id);

    expect($usage)->toBeInstanceOf(MessageUsage::class)
        ->and($usage->model)->toBe('claude-haiku-4-5')
        ->and($usage->stopReason)->toBe('end_turn')
        ->and([$usage->inputTokens, $usage->outputTokens, $usage->cacheReadTokens, $usage->cacheWriteTokens])->toBe([1200, 80, 3000, 500])
        ->and($usage->estimated)->toBeFalse()
        ->and($this->rateLimits->getTokenCount(1, date('Y-m-d')))->toBe(4780)
    ;

    $lines = array_values(array_filter(file($this->logFile), fn (string $l): bool => str_contains($l, 'ai_response ')));
    expect($lines)->toHaveCount(1);

    $log = json_decode(substr($lines[0], strpos($lines[0], 'ai_response ') + 12), true);
    expect($log)->toMatchArray([
        'chat_id' => $this->chat->id,
        'message_id' => $message->id,
        'user_id' => 1,
        'model' => 'claude-haiku-4-5',
        'stop_reason' => 'end_turn',
        'tool_calls' => 1,
        'input_tokens' => 1200,
        'output_tokens' => 80,
        'cache_read_tokens' => 3000,
        'cache_write_tokens' => 500,
        'usage_estimated' => false,
        'stopped_by_user' => false,
        'error' => false,
    ])
        ->and($log['ttft_ms'])->toBeInt()
        ->and($log['total_ms'])->toBeInt()
    ;
    expect($lines[0])->not->toContain('private question')
        ->not->toContain('secret document body')
        ->not->toContain('Hi there')
    ;
});

it('estimates usage when the provider reports none', function (): void {
    $this->ai->turns[] = [new TextDelta(str_repeat('a', 400)), new StreamEnd(StopReason::EndTurn)];

    ($this->send)(str_repeat('q', 80));
    $usage = $this->messages->findUsage(($this->lastAssistant)()->id);

    expect($usage->estimated)->toBeTrue()
        ->and($usage->inputTokens)->toBe(20)
        ->and($usage->outputTokens)->toBe(100)
    ;
});

it('records a stopped response with estimated usage', function (): void {
    $this->ai->turns[] = [
        new TextDelta('Once upon'),
        function (): null {
            $this->sessions->requestStop($this->chat->id, 1);

            return null;
        },
        new TextDelta(' a time'),
        new StreamEnd(StopReason::EndTurn),
    ];

    ($this->send)('Tell a story');
    $message = ($this->lastAssistant)();
    $usage = $this->messages->findUsage($message->id);

    expect($message->content)->toBe('Once upon')
        ->and($usage->stopReason)->toBe(MessageUsage::STOP_REASON_USER)
        ->and($usage->estimated)->toBeTrue()
    ;

    $complete = array_values(array_filter($this->bus->ofType(MessageStreamingEvent::class), fn ($e): bool => $e->isComplete));
    expect($complete[0]->fullContent)->toBe('Once upon ⏹');
});

it('rejects a message once the daily token limit is used up', function (): void {
    $this->limits = ['tokens' => 1000];
    $this->ai->turns[] = [new TextDelta('Hi'), new StreamEnd(StopReason::EndTurn, new Usage(inputTokens: 900, outputTokens: 200))];

    expect(($this->send)('First'))->toBe(204);
    expect(($this->send)('Second'))->toBe(429);

    $event = $this->bus->ofType(RateLimitExceededEvent::class)[0];
    expect($event->used)->toBe(1100)
        ->and($event->limit)->toBe(1000)
        ->and(\count($this->messages->findByChat($this->chat->id)))->toBe(2)
    ;
});

it('rejects a message over the hourly request limit', function (): void {
    $this->limits = ['hourly' => 1];
    $this->ai->turns[] = [new TextDelta('Hi'), new StreamEnd(StopReason::EndTurn)];

    expect(($this->send)('First'))->toBe(204);
    expect(($this->send)('Second'))->toBe(429);
});

it('rate limits the first answer of a new chat', function (): void {
    $this->limits = ['daily' => 0];
    $this->messages->save(Message::user($this->chat->id, 'Created with the chat'));

    expect(($this->send)('', 'generate'))->toBe(429);
    expect($this->ai->histories)->toBe([]);
});

it('counts the first answer of a new chat toward the limits', function (): void {
    $this->messages->save(Message::user($this->chat->id, 'Created with the chat'));
    $this->ai->turns[] = [new TextDelta('Hi'), new StreamEnd(StopReason::EndTurn)];

    expect(($this->send)('', 'generate'))->toBe(204);
    expect($this->rateLimits->getMessageCount(1, date('Y-m-d')))->toBe(1);
});

it('trims the history to the token budget', function (): void {
    $this->contextMaxTokens = 100;
    foreach (['old question', 'old answer'] as $i => $text) {
        $this->messages->save($i === 0 ? Message::user($this->chat->id, str_repeat('x', 2000)) : Message::assistant($this->chat->id, str_repeat('y', 200)));
    }
    $this->ai->turns[] = [new TextDelta('ok'), new StreamEnd(StopReason::EndTurn)];

    ($this->send)('New question');

    $history = $this->ai->histories[0];
    expect(array_column($history, 'role'))->toBe(['user', 'assistant', 'user'])
        ->and($history[0]['content'])->toEndWith('… [truncated]')
        ->and($history[1]['content'])->toBe(str_repeat('y', 200))
        ->and($history[2]['content'])->toBe('New question')
    ;
});
