<?php

declare(strict_types=1);

namespace App;

use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Repository\VoteRepositoryInterface;
use App\Infrastructure\EventBus\EventBusInterface;
use App\Infrastructure\EventBus\SwooleEventBus;
use App\Infrastructure\Http\Handler\ChatHandler;
use App\Infrastructure\Http\Handler\Command\ChatCommandHandler;
use App\Infrastructure\Http\Handler\Command\MessageCommandHandler;
use App\Infrastructure\Http\Handler\HomeHandler;
use App\Infrastructure\Http\Handler\Query\ChatQueryHandler;
use App\Infrastructure\Http\Handler\Query\HistoryQueryHandler;
use App\Infrastructure\Http\Handler\UpdatesHandler;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteDocumentRepository;
use App\Infrastructure\Persistence\SqliteMessageRepository;
use App\Infrastructure\Persistence\SqliteVoteRepository;
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

                // Handlers
                HomeHandler::class => fn (ContainerInterface $container): HomeHandler => new HomeHandler(
                    $container->get(TemplateRenderer::class),
                    $container->get(ChatRepositoryInterface::class),
                ),
                ChatHandler::class => fn (ContainerInterface $container): ChatHandler => new ChatHandler(
                    $container->get(TemplateRenderer::class),
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(MessageRepositoryInterface::class),
                ),
                UpdatesHandler::class => fn (ContainerInterface $container): UpdatesHandler => new UpdatesHandler(
                    $container->get(EventBusInterface::class),
                ),

                // Command Handlers
                ChatCommandHandler::class => fn (ContainerInterface $container): ChatCommandHandler => new ChatCommandHandler(
                    $container->get(ChatRepositoryInterface::class),
                    $container->get(EventBusInterface::class),
                ),
                MessageCommandHandler::class => fn (ContainerInterface $container): MessageCommandHandler => new MessageCommandHandler(
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
            ],
            'aliases' => [],
        ];
    }
}
