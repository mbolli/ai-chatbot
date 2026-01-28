<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Listener;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\MessageStreamingEvent;
use App\Infrastructure\EventBus\EventBusInterface;
use App\Infrastructure\Session\SwooleTableSessionPersistence;
use Mezzio\Swoole\Event\RequestEvent;
use starfederation\datastar\events\MergeFragments;
use starfederation\datastar\events\PatchElements;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Coroutine\Channel;
use Swoole\Http\Response as SwooleHttpResponse;
use Swoole\Timer;

/**
 * SSE Request Listener for Datastar real-time updates.
 *
 * This listener intercepts requests to /updates and handles them as Server-Sent Events,
 * streaming Datastar fragments to the client in real-time.
 *
 * Uses Channel-based blocking (like timeline project) instead of Coroutine::sleep
 * for proper connection management and cleanup.
 */
final class SseRequestListener {
    private const string SSE_PATH = '/updates';
    private const int KEEP_ALIVE_INTERVAL_MS = 30000; // 30 seconds

    public function __construct(
        private readonly EventBusInterface $eventBus,
        private readonly SwooleTableSessionPersistence $sessionPersistence,
    ) {}

    public function __invoke(RequestEvent $event): void {
        $request = $event->getRequest();
        $uri = $request->server['request_uri'] ?? '';

        // Only handle SSE endpoint
        if ($uri !== self::SSE_PATH) {
            return;
        }

        $response = $event->getResponse();

        // Get user ID from session
        $userId = $this->getUserIdFromRequest($request);

        // Set SSE headers using Datastar SDK
        foreach (ServerSentEventGenerator::headers() as $name => $value) {
            $response->header($name, $value);
        }

        // Channel for blocking until close signal
        $channel = new Channel(1);

        // Subscribe to events for this user
        $subscriptionId = $this->eventBus->subscribe($userId, function (object $eventData) use ($response, $channel): void {
            if (! $response->isWritable()) {
                $channel->push(true);
                return;
            }
            $this->sendEvent($response, $eventData);
        });

        // Send initial connection event
        $this->sendDatastarFragment($response, '<div id="connection-status" data-connected="true"><span class="dot connected"></span><span>Connected</span></div>');

        // Keep-alive timer
        $timerId = Timer::tick(self::KEEP_ALIVE_INTERVAL_MS, function () use ($response, $channel): void {
            if (! $response->isWritable()) {
                $channel->push(true);
                return;
            }
            $response->write(": keep-alive\n\n");
        });

        // Mark response as sent BEFORE blocking - stops propagation to other listeners
        $event->responseSent();

        // Block until channel receives close signal
        $channel->pop();

        // Cleanup
        Timer::clear($timerId);
        $this->eventBus->unsubscribe($subscriptionId);
    }

    private function getUserIdFromRequest(\Swoole\Http\Request $request): int {
        $cookies = $request->cookie ?? [];
        $sessionId = $cookies['PHPSESSID'] ?? null;

        if ($sessionId !== null) {
            $sessionData = $this->sessionPersistence->getSessionData($sessionId);
            if (isset($sessionData['authenticated_user'])) {
                return (int) $sessionData['authenticated_user'];
            }
        }

        // Default to guest user ID 1 if no session
        return 1;
    }

    private function sendEvent(SwooleHttpResponse $response, object $event): void {
        $eventClass = basename(str_replace('\\', '/', \get_class($event)));

        $html = match ($eventClass) {
            'MessageStreamingEvent' => $this->handleMessageStreaming($event),
            'ChatUpdatedEvent' => $this->handleChatUpdated($event),
            'DocumentUpdatedEvent' => $this->handleDocumentUpdated($event),
            default => null,
        };

        if ($html !== null) {
            $this->sendDatastarFragment($response, $html);
        }
    }

    private function handleMessageStreaming(MessageStreamingEvent $event): string {
        if ($event->isComplete) {
            return '<div id="message-' . $event->messageId . '-streaming" data-complete="true"></div>';
        }

        $escaped = htmlspecialchars($event->chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<span data-append-to="#message-' . $event->messageId . '-content">' . $escaped . '</span>';
    }

    private function handleChatUpdated(ChatUpdatedEvent $event): string {
        return '<div id="chat-update-signal" data-chat-id="' . $event->chatId . '" data-action="' . $event->action . '"></div>';
    }

    private function handleDocumentUpdated(DocumentUpdatedEvent $event): string {
        return '<div id="document-update-signal" data-document-id="' . $event->documentId . '" data-action="' . $event->action . '"></div>';
    }

    private function sendDatastarFragment(SwooleHttpResponse $response, string $html): void {
        if (! $response->isWritable()) {
            return;
        }

        // Use Datastar SDK's MergeFragments event
        $event = new PatchElements($html);
        $response->write($event->getOutput());
    }
}
