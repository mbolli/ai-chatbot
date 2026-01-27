<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler;

use App\Infrastructure\EventBus\EventBusInterface;
use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Coroutine;

/**
 * SSE endpoint for real-time updates via Datastar.
 *
 * This handler is special - it keeps the connection open and streams events.
 * In Swoole, this needs special handling (see bin/server.php).
 */
final class UpdatesHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly EventBusInterface $eventBus,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        // This is a marker response - actual SSE handling happens in the Swoole server
        // The server detects this route and handles SSE streaming directly

        $response = new Response();

        return $response
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
        ;
    }

    /**
     * Handle SSE streaming (called directly by Swoole server).
     */
    public function handleSse(\Swoole\Http\Response $response, int $userId): void {
        // Set SSE headers
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        // Subscribe to events for this user
        $subscriptionId = $this->eventBus->subscribe($userId, function (object $event) use ($response): void {
            $this->sendEvent($response, $event);
        });

        // Send initial connection event
        $this->sendDatastarFragment($response, '<div id="connection-status" data-connected="true"></div>');

        // Keep connection alive with periodic heartbeats
        // The connection will be closed when the client disconnects
        // or when the Swoole coroutine is cancelled

        try {
            // @phpstan-ignore while.alwaysTrue (intentional infinite loop for SSE)
            while (true) {
                // Send heartbeat every 30 seconds
                Coroutine::sleep(30);
                $response->write(": heartbeat\n\n");
            }
        } finally {
            $this->eventBus->unsubscribe($subscriptionId);
        }
    }

    private function sendEvent(\Swoole\Http\Response $response, object $event): void {
        $eventClass = \get_class($event);
        $eventType = basename(str_replace('\\', '/', $eventClass));

        // Route event to appropriate handler
        match ($eventType) {
            'MessageStreamingEvent' => $this->handleMessageStreaming($response, $event),
            'ChatUpdatedEvent' => $this->handleChatUpdated($response, $event),
            'DocumentUpdatedEvent' => $this->handleDocumentUpdated($response, $event),
            default => null,
        };
    }

    private function handleMessageStreaming(\Swoole\Http\Response $response, object $event): void {
        if ($event->isComplete) {
            // Send complete message
            $html = '<div id="message-' . $event->messageId . '-streaming" data-complete="true"></div>';
        } else {
            // Append chunk to message
            $escaped = htmlspecialchars($event->chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html = '<span data-append-to="#message-' . $event->messageId . '-content">' . $escaped . '</span>';
        }

        $this->sendDatastarFragment($response, $html);
    }

    private function handleChatUpdated(\Swoole\Http\Response $response, object $event): void {
        // Re-render sidebar or chat list
        // For now, just signal that an update occurred
        $html = '<div id="chat-update-signal" data-chat-id="' . $event->chatId . '" data-action="' . $event->action . '"></div>';
        $this->sendDatastarFragment($response, $html);
    }

    private function handleDocumentUpdated(\Swoole\Http\Response $response, object $event): void {
        $html = '<div id="document-update-signal" data-document-id="' . $event->documentId . '" data-action="' . $event->action . '"></div>';
        $this->sendDatastarFragment($response, $html);
    }

    private function sendDatastarFragment(\Swoole\Http\Response $response, string $html): void {
        // Datastar fragment format
        $data = "event: datastar-merge-fragments\n";
        $data .= 'data: fragments ' . mb_trim($html) . "\n\n";

        $response->write($data);
    }
}
