<?php

declare(strict_types=1);

namespace App;

use App\Application\ChatCommands;
use App\Application\DocumentCommands;
use App\Application\MessageCommands;
use App\Application\SuggestionCommands;
use App\Application\VoteCommands;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Repository\SuggestionRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Repository\VoteRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Domain\Service\RateLimitService;
use App\Infrastructure\AI\AIService;
use App\Infrastructure\AI\StreamingSessionManager;
use App\Infrastructure\Auth\AuthService;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use App\Infrastructure\Persistence\SqliteMessageRepository;
use App\Infrastructure\Persistence\SqliteRateLimitRepository;
use App\Infrastructure\Persistence\SqliteSuggestionRepository;
use App\Infrastructure\Persistence\SqliteVoteRepository;
use App\Infrastructure\Repository\SqliteUserRepository;
use App\Infrastructure\Template\TemplateRenderer;
use App\Web\LiveState;
use App\Web\ViaEventBus;

/**
 * Builds the shared services once. They live for the worker's lifetime and are used by every
 * coroutine, so none of them may hold per-request state.
 */
final class Container {
    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly array $config,
        private readonly string $rootDir,
    ) {}

    public function pdo(): \PDO {
        return $this->shared(\PDO::class, function (): \PDO {
            $pdo = new \PDO('sqlite:' . ($this->config['database']['path'] ?? $this->rootDir . '/data/db.sqlite'));
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            // WAL: a write appends to the log instead of creating, syncing and deleting a rollback journal,
            // which halves the cost of a page view that creates a guest user
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');

            return $pdo;
        });
    }

    public function liveState(): LiveState {
        return $this->shared(LiveState::class, static fn (): LiveState => new LiveState());
    }

    public function eventBus(): ViaEventBus {
        return $this->shared(ViaEventBus::class, fn (): ViaEventBus => new ViaEventBus($this->liveState()));
    }

    public function renderer(): TemplateRenderer {
        return $this->shared(TemplateRenderer::class, fn (): TemplateRenderer => new TemplateRenderer([
            'app' => [$this->rootDir . '/templates/app'],
            'layout' => [$this->rootDir . '/templates/layout'],
            'partials' => [$this->rootDir . '/templates/partials'],
        ]));
    }

    public function users(): UserRepositoryInterface {
        return $this->shared(UserRepositoryInterface::class, fn (): UserRepositoryInterface => new SqliteUserRepository($this->pdo()));
    }

    public function chats(): ChatRepositoryInterface {
        return $this->shared(ChatRepositoryInterface::class, fn (): ChatRepositoryInterface => new SqliteChatRepository($this->pdo()));
    }

    public function messages(): MessageRepositoryInterface {
        return $this->shared(MessageRepositoryInterface::class, fn (): MessageRepositoryInterface => new SqliteMessageRepository($this->pdo()));
    }

    public function documents(): DocumentRepositoryInterface {
        return $this->shared(DocumentRepositoryInterface::class, fn (): DocumentRepositoryInterface => new SqliteDocumentRepository($this->pdo()));
    }

    public function votes(): VoteRepositoryInterface {
        return $this->shared(VoteRepositoryInterface::class, fn (): VoteRepositoryInterface => new SqliteVoteRepository($this->pdo()));
    }

    public function suggestions(): SuggestionRepositoryInterface {
        return $this->shared(SuggestionRepositoryInterface::class, fn (): SuggestionRepositoryInterface => new SqliteSuggestionRepository($this->pdo()));
    }

    public function auth(): AuthService {
        return $this->shared(AuthService::class, fn (): AuthService => new AuthService($this->users()));
    }

    public function rateLimits(): RateLimitService {
        return $this->shared(RateLimitService::class, function (): RateLimitService {
            $limits = $this->config['rate_limits'] ?? [];

            return new RateLimitService(
                rateLimitRepository: new SqliteRateLimitRepository($this->pdo()),
                userRepository: $this->users(),
                guestDailyLimit: $limits['guest']['daily_messages'] ?? 20,
                registeredDailyLimit: $limits['registered']['daily_messages'] ?? 100,
                guestHourlyLimit: $limits['guest']['requests_per_hour'] ?? 0,
                registeredHourlyLimit: $limits['registered']['requests_per_hour'] ?? 0,
                guestDailyTokenLimit: $limits['guest']['daily_tokens'] ?? 0,
                registeredDailyTokenLimit: $limits['registered']['daily_tokens'] ?? 0,
            );
        });
    }

    public function ai(): AIServiceInterface {
        return $this->shared(AIServiceInterface::class, function (): AIServiceInterface {
            $ai = $this->config['ai'] ?? [];

            return new AIService(
                anthropicApiKey: $ai['anthropic_api_key'] ?? null,
                openaiApiKey: $ai['openai_api_key'] ?? null,
                documentRepository: $this->documents(),
                maxTokens: $ai['max_tokens'] ?? 2048,
                defaultModel: $ai['default_model'] ?? null,
                productionMode: $this->isProduction(),
                responseFormat: $ai['response_format'] ?? 'markdown',
                suggestionRepository: $this->suggestions(),
                chatRepository: $this->chats(),
                eventBus: $this->eventBus(),
            );
        });
    }

    public function streamingSessions(): StreamingSessionManager {
        return $this->shared(StreamingSessionManager::class, static fn (): StreamingSessionManager => new StreamingSessionManager());
    }

    public function chatCommands(): ChatCommands {
        return $this->shared(ChatCommands::class, fn (): ChatCommands => new ChatCommands(
            $this->chats(),
            $this->messages(),
            $this->eventBus(),
            $this->ai(),
        ));
    }

    public function messageCommands(): MessageCommands {
        return $this->shared(MessageCommands::class, fn (): MessageCommands => new MessageCommands(
            $this->chats(),
            $this->messages(),
            $this->documents(),
            $this->eventBus(),
            $this->ai(),
            $this->streamingSessions(),
            $this->rateLimits(),
            contextMaxTokens: $this->config['ai']['context_max_tokens'] ?? 8000,
            testCommandsEnabled: !$this->isProduction(),
        ));
    }

    public function voteCommands(): VoteCommands {
        return $this->shared(VoteCommands::class, fn (): VoteCommands => new VoteCommands(
            $this->votes(),
            $this->chats(),
            $this->messages(),
            $this->eventBus(),
        ));
    }

    public function documentCommands(): DocumentCommands {
        return $this->shared(DocumentCommands::class, fn (): DocumentCommands => new DocumentCommands(
            $this->documents(),
            $this->chats(),
            $this->eventBus(),
        ));
    }

    public function suggestionCommands(): SuggestionCommands {
        return $this->shared(SuggestionCommands::class, fn (): SuggestionCommands => new SuggestionCommands(
            $this->suggestions(),
            $this->documents(),
            $this->chats(),
            $this->eventBus(),
        ));
    }

    public function isProduction(): bool {
        return ($this->config['app']['env'] ?? 'production') === 'production';
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param \Closure(): T   $factory
     *
     * @return T
     */
    private function shared(string $id, \Closure $factory): object {
        /** @var T */
        return $this->instances[$id] ??= $factory();
    }
}
