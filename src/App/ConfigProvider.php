<?php

declare(strict_types=1);

namespace App;

use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Repository\VoteRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
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
use App\Infrastructure\Http\Handler\HomeHandler;
use App\Infrastructure\Http\Handler\Query\ChatQueryHandler;
use App\Infrastructure\Http\Handler\Query\DocumentQueryHandler;
use App\Infrastructure\Http\Handler\Query\HistoryQueryHandler;
use App\Infrastructure\Http\Listener\SseRequestListener;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use App\Infrastructure\Persistence\SqliteMessageRepository;
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

                // AI Service
                AIServiceInterface::class => function (ContainerInterface $container): AIServiceInterface {
                    $config = $container->get('config');

                    return new LLPhantAIService(
                        anthropicApiKey: $config['ai']['anthropic_api_key'] ?? $_ENV['ANTHROPIC_API_KEY'] ?? null,
                        openaiApiKey: $config['ai']['openai_api_key'] ?? $_ENV['OPENAI_API_KEY'] ?? null,
                        documentRepository: $container->get(DocumentRepositoryInterface::class),
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
                MessageCommandHandler::class => fn (ContainerInterface $container): MessageCommandHandler => new MessageCommandHandler(
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(MessageRepositoryInterface::class),
                    $container->get(DocumentRepositoryInterface::class),
                    $container->get(EventBusInterface::class),
                    $container->get(AIServiceInterface::class),
                    $container->get(StreamingSessionManager::class),
                ),
                DocumentCommandHandler::class => fn (ContainerInterface $container): DocumentCommandHandler => new DocumentCommandHandler(
                    $container->get(DocumentRepositoryInterface::class),
                    $container->get(ChatRepositoryInterface::class),
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
            ],
            'aliases' => [],
        ];
    }
}
