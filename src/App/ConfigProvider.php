<?php

declare(strict_types=1);

namespace App;

use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Repository\RateLimitRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Repository\VoteRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Domain\Service\RateLimitService;
use App\Infrastructure\AI\LLPhantAIService;
use App\Infrastructure\AI\StreamingSessionManager;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\Auth\AuthService;
use App\Infrastructure\EventBus\EventBusInterface;
use App\Infrastructure\EventBus\SwooleEventBus;
use App\Infrastructure\Http\Handler\AuthHandler;
use App\Infrastructure\Http\Handler\ChatHandler;
use App\Infrastructure\Http\Handler\Command\ChatCommandHandler;
use App\Infrastructure\Http\Handler\Command\DocumentCommandHandler;
use App\Infrastructure\Http\Handler\Command\MessageCommandHandler;
use App\Infrastructure\Http\Handler\Command\VoteCommandHandler;
use App\Infrastructure\Http\Handler\HomeHandler;
use App\Infrastructure\Http\Handler\Query\ChatQueryHandler;
use App\Infrastructure\Http\Handler\Query\DocumentQueryHandler;
use App\Infrastructure\Http\Handler\Query\HistoryQueryHandler;
use App\Infrastructure\Http\Listener\CleanupTimerListener;
use App\Infrastructure\Http\Listener\SseRequestListener;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use App\Infrastructure\Persistence\SqliteMessageRepository;
use App\Infrastructure\Persistence\SqliteRateLimitRepository;
use App\Infrastructure\Persistence\SqliteVoteRepository;
use App\Infrastructure\Repository\SqliteUserRepository;
use App\Infrastructure\Session\SwooleTableSessionPersistence;
use App\Infrastructure\Template\TemplateRenderer;
use Psr\Container\ContainerInterface;

class ConfigProvider {
    /**
     * @return array{dependencies: array<string, mixed>}
     */
    public function __invoke(): array {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    /**
     * @return array{invokables: array<string, class-string>, factories: array<string, callable>}
     */
    public function getDependencies(): array {
        return [
            'invokables' => [],
            'factories' => [
                // Database
                \PDO::class => function (ContainerInterface $container): \PDO {
                    $config = $container->get('config');
                    $dbPath = $config['database']['path'] ?? getcwd() . '/data/db.sqlite';

                    $pdo = new \PDO('sqlite:' . $dbPath);
                    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
                    $pdo->exec('PRAGMA foreign_keys = ON');

                    return $pdo;
                },

                // Event Bus (singleton for SSE)
                EventBusInterface::class => fn (): EventBusInterface => SwooleEventBus::getInstance(),

                // Session Persistence (Swoole Table-based, coroutine-safe)
                SwooleTableSessionPersistence::class => function (): SwooleTableSessionPersistence {
                    // Use shared table for persistence across requests
                    return new SwooleTableSessionPersistence(
                        cookieName: 'PHPSESSID',
                        sessionTtl: 3600 * 24 * 7, // 1 week
                        table: SwooleTableSessionPersistence::getSharedTable(),
                    );
                },

                // User Repository
                UserRepositoryInterface::class => fn (ContainerInterface $container): UserRepositoryInterface => new SqliteUserRepository($container->get(\PDO::class)),

                // Auth Service
                AuthService::class => fn (ContainerInterface $container): AuthService => new AuthService(
                    $container->get(UserRepositoryInterface::class),
                ),

                // Auth Middleware
                AuthMiddleware::class => fn (ContainerInterface $container): AuthMiddleware => new AuthMiddleware(
                    $container->get(AuthService::class),
                    $container->get(SwooleTableSessionPersistence::class),
                ),

                // Template Renderer
                TemplateRenderer::class => function (ContainerInterface $container): TemplateRenderer {
                    $config = $container->get('config');

                    return new TemplateRenderer($config['templates']['paths'] ?? []);
                },

                // Repositories
                ChatRepositoryInterface::class => fn (ContainerInterface $container): ChatRepositoryInterface => new SqliteChatRepository($container->get(\PDO::class)),
                MessageRepositoryInterface::class => fn (ContainerInterface $container): MessageRepositoryInterface => new SqliteMessageRepository($container->get(\PDO::class)),
                DocumentRepositoryInterface::class => fn (ContainerInterface $container): DocumentRepositoryInterface => new SqliteDocumentRepository($container->get(\PDO::class)),
                VoteRepositoryInterface::class => fn (ContainerInterface $container): VoteRepositoryInterface => new SqliteVoteRepository($container->get(\PDO::class)),
                RateLimitRepositoryInterface::class => fn (ContainerInterface $container): RateLimitRepositoryInterface => new SqliteRateLimitRepository($container->get(\PDO::class)),

                // Rate Limit Service
                RateLimitService::class => function (ContainerInterface $container): RateLimitService {
                    $config = $container->get('config');
                    $rateLimits = $config['rate_limits'] ?? [];

                    return new RateLimitService(
                        rateLimitRepository: $container->get(RateLimitRepositoryInterface::class),
                        userRepository: $container->get(UserRepositoryInterface::class),
                        guestDailyLimit: $rateLimits['guest']['daily_messages'] ?? 20,
                        registeredDailyLimit: $rateLimits['registered']['daily_messages'] ?? 100,
                    );
                },

                // AI Service
                AIServiceInterface::class => function (ContainerInterface $container): AIServiceInterface {
                    $config = $container->get('config');
                    $aiConfig = $config['ai'] ?? [];
                    $appConfig = $config['app'] ?? [];

                    // Production mode limits model selection to cost-effective options
                    $isProduction = ($appConfig['env'] ?? 'production') === 'production';

                    return new LLPhantAIService(
                        anthropicApiKey: $aiConfig['anthropic_api_key'] ?? null,
                        openaiApiKey: $aiConfig['openai_api_key'] ?? null,
                        documentRepository: $container->get(DocumentRepositoryInterface::class),
                        maxTokens: $aiConfig['max_tokens'] ?? 2048,
                        defaultModel: $aiConfig['default_model'] ?? null,
                        productionMode: $isProduction,
                    );
                },

                // Streaming Session Manager (singleton for Swoole)
                StreamingSessionManager::class => fn (): StreamingSessionManager => new StreamingSessionManager(),

                // Handlers
                HomeHandler::class => fn (ContainerInterface $container): HomeHandler => new HomeHandler(
                    $container->get(TemplateRenderer::class),
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(AIServiceInterface::class),
                ),
                ChatHandler::class => fn (ContainerInterface $container): ChatHandler => new ChatHandler(
                    $container->get(TemplateRenderer::class),
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(MessageRepositoryInterface::class),
                    $container->get(DocumentRepositoryInterface::class),
                    $container->get(VoteRepositoryInterface::class),
                    $container->get(AIServiceInterface::class),
                ),

                // Auth Handler
                AuthHandler::class => fn (): AuthHandler => new AuthHandler(),

                // Command Handlers
                ChatCommandHandler::class => fn (ContainerInterface $container): ChatCommandHandler => new ChatCommandHandler(
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(MessageRepositoryInterface::class),
                    $container->get(EventBusInterface::class),
                ),
                MessageCommandHandler::class => function (ContainerInterface $container): MessageCommandHandler {
                    $config = $container->get('config');
                    $aiConfig = $config['ai'] ?? [];

                    return new MessageCommandHandler(
                        $container->get(ChatRepositoryInterface::class),
                        $container->get(MessageRepositoryInterface::class),
                        $container->get(DocumentRepositoryInterface::class),
                        $container->get(EventBusInterface::class),
                        $container->get(AIServiceInterface::class),
                        $container->get(StreamingSessionManager::class),
                        $container->get(RateLimitService::class),
                        contextRecentMessages: $aiConfig['context_recent_messages'] ?? 6,
                        contextMaxOlderChars: $aiConfig['context_max_older_chars'] ?? 500,
                    );
                },
                DocumentCommandHandler::class => fn (ContainerInterface $container): DocumentCommandHandler => new DocumentCommandHandler(
                    $container->get(DocumentRepositoryInterface::class),
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(EventBusInterface::class),
                ),
                VoteCommandHandler::class => fn (ContainerInterface $container): VoteCommandHandler => new VoteCommandHandler(
                    $container->get(VoteRepositoryInterface::class),
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(MessageRepositoryInterface::class),
                    $container->get(EventBusInterface::class),
                ),

                // Query Handlers
                ChatQueryHandler::class => fn (ContainerInterface $container): ChatQueryHandler => new ChatQueryHandler(
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(MessageRepositoryInterface::class),
                ),
                HistoryQueryHandler::class => fn (ContainerInterface $container): HistoryQueryHandler => new HistoryQueryHandler(
                    $container->get(ChatRepositoryInterface::class),
                ),
                DocumentQueryHandler::class => fn (ContainerInterface $container): DocumentQueryHandler => new DocumentQueryHandler(
                    $container->get(DocumentRepositoryInterface::class),
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(EventBusInterface::class),
                ),

                // SSE Listener (for mezzio-swoole RequestEvent handling)
                SseRequestListener::class => fn (ContainerInterface $container): SseRequestListener => new SseRequestListener(
                    $container->get(EventBusInterface::class),
                    $container->get(SwooleTableSessionPersistence::class),
                    $container->get(TemplateRenderer::class),
                    $container->get(DocumentRepositoryInterface::class),
                ),

                // Cleanup Timer Listener (for mezzio-swoole WorkerStartEvent)
                CleanupTimerListener::class => fn (ContainerInterface $container): CleanupTimerListener => new CleanupTimerListener(
                    $container->get(StreamingSessionManager::class),
                    $container->get(SwooleTableSessionPersistence::class),
                ),
            ],
            'aliases' => [],
        ];
    }
}
