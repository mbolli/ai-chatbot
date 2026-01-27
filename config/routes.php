<?php

declare(strict_types=1);

use App\Infrastructure\Http\Handler\AuthHandler;
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

    // Auth endpoints
    $app->post('/auth/login', AuthHandler::class, 'auth.login');
    $app->post('/auth/register', AuthHandler::class, 'auth.register');
    $app->post('/auth/logout', AuthHandler::class, 'auth.logout');
    $app->post('/auth/upgrade', AuthHandler::class, 'auth.upgrade');
    $app->get('/auth/status', AuthHandler::class, 'auth.status');

    // Query endpoints (GET)
    $app->get('/api/chats', HistoryQueryHandler::class, 'api.chats.list');
    $app->get('/api/chats/{id:[a-f0-9-]+}', ChatQueryHandler::class, 'api.chat.show');
    $app->get('/api/chats/{id:[a-f0-9-]+}/messages', ChatQueryHandler::class, 'api.chat.messages');

    // Command endpoints (POST/PUT/DELETE)
    $app->post('/cmd/chat', ChatCommandHandler::class, 'cmd.chat.create');
    $app->delete('/cmd/chat/{id:[a-f0-9-]+}', ChatCommandHandler::class, 'cmd.chat.delete');
    $app->patch('/cmd/chat/{id:[a-f0-9-]+}/visibility', ChatCommandHandler::class, 'cmd.chat.visibility');

    $app->post('/cmd/chat/{chatId:[a-f0-9-]+}/message', MessageCommandHandler::class, 'cmd.message.send');
};
