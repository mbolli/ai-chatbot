#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\EventBus\SwooleEventBus;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Mezzio\Application;
use Mezzio\MiddlewareFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

// Setup error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

// Initialize database if needed
$dbPath = getcwd() . '/data/db.sqlite';
if (!file_exists($dbPath)) {
    echo "Initializing database...\n";
    if (!is_dir(dirname($dbPath))) {
        mkdir(dirname($dbPath), 0755, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->exec(file_get_contents(getcwd() . '/data/schema.sql'));
    echo "Database initialized.\n";
}

/** @var ContainerInterface $container */
$container = require 'config/container.php';

/** @var Application $app */
$app = $container->get(Application::class);
$factory = $container->get(MiddlewareFactory::class);

// Execute programmatic/declarative middleware pipeline and target routing
(require 'config/pipeline.php')($app, $factory, $container);
(require 'config/routes.php')($app, $factory, $container);

// Get Swoole HTTP server
$server = new Server('0.0.0.0', 8080);

$server->set([
    'worker_num' => swoole_cpu_num(),
    'enable_coroutine' => true,
    'document_root' => getcwd() . '/public',
    'enable_static_handler' => true,
    'static_handler_locations' => ['/css', '/js', '/images', '/favicon.ico'],
]);

$server->on('start', function ($server): void {
    echo "AI Chatbot server started at http://0.0.0.0:8080\n";
    echo "Press Ctrl+C to stop.\n";
});

$server->on('request', function (Request $swooleRequest, Response $swooleResponse) use ($app, $container): void {
    // Special handling for SSE endpoint
    if ($swooleRequest->server['request_uri'] === '/updates') {
        handleSseRequest($swooleRequest, $swooleResponse, $container);

        return;
    }

    // Convert Swoole request to PSR-7
    $request = convertSwooleRequest($swooleRequest);

    try {
        $response = $app->handle($request);

        // Send response
        $swooleResponse->status($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->header($name, $value);
            }
        }

        $swooleResponse->end((string) $response->getBody());
    } catch (Throwable $e) {
        error_log('Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        $swooleResponse->status(500);
        $swooleResponse->end('Internal Server Error');
    }
});

$server->start();

/**
 * Handle SSE requests directly (bypassing Mezzio for streaming).
 */
function handleSseRequest(Request $request, Response $response, ContainerInterface $container): void {
    // TODO: Get user ID from session cookie
    $userId = 1;

    // Set SSE headers
    $response->header('Content-Type', 'text/event-stream');
    $response->header('Cache-Control', 'no-cache');
    $response->header('Connection', 'keep-alive');
    $response->header('X-Accel-Buffering', 'no');
    $response->header('Access-Control-Allow-Origin', '*');

    /** @var SwooleEventBus $eventBus */
    $eventBus = SwooleEventBus::getInstance();

    // Subscribe to events
    $subscriptionId = $eventBus->subscribe($userId, function (object $event) use ($response): void {
        sendSseEvent($response, $event);
    });

    // Send initial connection event
    $response->write("event: datastar-merge-fragments\n");
    $response->write("data: fragments <div id=\"connection-status\" data-connected=\"true\"></div>\n\n");

    // Keep connection alive
    $running = true;

    // Handle client disconnect
    $response->on('close', function () use (&$running, $eventBus, $subscriptionId): void {
        $running = false;
        $eventBus->unsubscribe($subscriptionId);
    });

    // Heartbeat loop
    Coroutine::create(function () use ($response, &$running): void {
        while ($running) {
            Coroutine::sleep(30);
            if ($running) {
                try {
                    $response->write(": heartbeat\n\n");
                } catch (Throwable $e) {
                    $running = false;
                }
            }
        }
    });
}

/**
 * Send an SSE event using Datastar format.
 */
function sendSseEvent(Response $response, object $event): void {
    $eventClass = basename(str_replace('\\', '/', get_class($event)));

    $html = match ($eventClass) {
        'MessageStreamingEvent' => handleMessageStreamingEvent($event),
        'ChatUpdatedEvent' => handleChatUpdatedEvent($event),
        'DocumentUpdatedEvent' => handleDocumentUpdatedEvent($event),
        default => null,
    };

    if ($html !== null) {
        $response->write("event: datastar-merge-fragments\n");
        $response->write('data: fragments ' . mb_trim($html) . "\n\n");
    }
}

function handleMessageStreamingEvent(object $event): string {
    if ($event->isComplete) {
        return '<div id="message-' . $event->messageId . '-streaming" data-complete="true"></div>';
    }

    $escaped = htmlspecialchars($event->chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '<span data-append-to="#message-' . $event->messageId . '-content">' . $escaped . '</span>';
}

function handleChatUpdatedEvent(object $event): string {
    return '<div id="chat-update-signal" data-chat-id="' . $event->chatId . '" data-action="' . $event->action . '"></div>';
}

function handleDocumentUpdatedEvent(object $event): string {
    return '<div id="document-update-signal" data-document-id="' . $event->documentId . '" data-action="' . $event->action . '"></div>';
}

/**
 * Convert Swoole request to PSR-7 ServerRequest.
 */
function convertSwooleRequest(Request $swooleRequest): ServerRequestInterface {
    $method = $swooleRequest->server['request_method'] ?? 'GET';
    $uri = $swooleRequest->server['request_uri'] ?? '/';
    $queryString = $swooleRequest->server['query_string'] ?? '';

    if ($queryString !== '') {
        $uri .= '?' . $queryString;
    }

    $headers = $swooleRequest->header ?? [];
    $cookies = $swooleRequest->cookie ?? [];
    $serverParams = array_change_key_case($swooleRequest->server ?? [], CASE_UPPER);

    // Add HTTP_ prefix to headers for server params
    foreach ($headers as $name => $value) {
        $key = 'HTTP_' . mb_strtoupper(str_replace('-', '_', $name));
        $serverParams[$key] = $value;
    }

    $body = new Stream('php://temp', 'wb+');
    $rawContent = $swooleRequest->rawContent();
    if ($rawContent !== false && $rawContent !== '') {
        $body->write($rawContent);
        $body->rewind();
    }

    // Laminas Diactoros ServerRequest constructor positional parameters:
    // ($serverParams, $uploadedFiles, $uri, $method, $body, $headers, $cookies, $queryParams, $parsedBody, $protocol)
    return new ServerRequest(
        $serverParams,
        [],  // uploadedFiles
        $uri,
        $method,
        $body,
        $headers,
        $cookies,
        $swooleRequest->get ?? [],  // queryParams
        $swooleRequest->post,  // parsedBody
        $serverParams['SERVER_PROTOCOL'] ?? '1.1'
    );
}
