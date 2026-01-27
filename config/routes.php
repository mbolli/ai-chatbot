<?php

declare(strict_types=1);

use App\Infrastructure\Http\Handler\ChatHandler;
use App\Infrastructure\Http\Handler\Command\ChatCommandHandler;
use App\Infrastructure\Http\Handler\Command\MessageCommandHandler;
use App\Infrastructure\Http\Handler\HomeHandler;
use App\Infrastructure\Http\Handler\Query\ChatQueryHandler;
use App\Infrastructure\Http\Handler\Query\HistoryQueryHandler;
use App\Infrastructure\Http\Handler\UpdatesHandler;
use Mezzio\Application;
use Mezzio\MiddlewareFactory;
use Psr\Container\ContainerInterface;

return static function (Application $app, MiddlewareFactory $factory, ContainerInterface $container): void {
    // Home
    $app->get('/', HomeHandler::class, 'home');

    // SSE Updates endpoint
    $app->get('/updates', UpdatesHandler::class, 'updates');

    // Chat pages
    $app->get('/chat/{id:[a-f0-9-]+}', ChatHandler::class, 'chat.show');

    // Query endpoints (GET)
    $app->get('/api/chats', HistoryQueryHandler::class . ':list', 'api.chats.list');
    $app->get('/api/chats/{id:[a-f0-9-]+}', ChatQueryHandler::class . ':show', 'api.chat.show');
    $app->get('/api/chats/{id:[a-f0-9-]+}/messages', ChatQueryHandler::class . ':messages', 'api.chat.messages');

    // Command endpoints (POST/PUT/DELETE)
    $app->post('/cmd/chat', ChatCommandHandler::class . ':create', 'cmd.chat.create');
    $app->delete('/cmd/chat/{id:[a-f0-9-]+}', ChatCommandHandler::class . ':delete', 'cmd.chat.delete');
    $app->patch('/cmd/chat/{id:[a-f0-9-]+}/visibility', ChatCommandHandler::class . ':visibility', 'cmd.chat.visibility');

    $app->post('/cmd/chat/{chatId:[a-f0-9-]+}/message', MessageCommandHandler::class . ':send', 'cmd.message.send');
};
