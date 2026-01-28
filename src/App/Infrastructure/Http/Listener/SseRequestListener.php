<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Listener;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\MessageStreamingEvent;
use App\Infrastructure\EventBus\EventBusInterface;
use App\Infrastructure\Session\SwooleTableSessionPersistence;
use App\Infrastructure\Template\TemplateRenderer;
use Mezzio\Swoole\Event\RequestEvent;
use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\events\ExecuteScript;
use starfederation\datastar\events\PatchElements;
use starfederation\datastar\events\PatchSignals;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
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
        private readonly TemplateRenderer $renderer,
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
            if (!$response->isWritable()) {
                $channel->push(true);

                return;
            }
            $this->sendEvent($response, $eventData);
        });

        // Send initial connection event
        $this->sendDatastarFragment($response, '<div id="connection-status" data-connected="true"><span class="dot connected"></span><span>Connected</span></div>');

        // Keep-alive timer
        $timerId = Timer::tick(self::KEEP_ALIVE_INTERVAL_MS, function () use ($response, $channel): void {
            if (!$response->isWritable()) {
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

    private function getUserIdFromRequest(Request $request): int {
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

        // Handle MessageStreamingEvent specially with append mode
        if ($eventClass === 'MessageStreamingEvent' && $event instanceof MessageStreamingEvent) {
            // Send chunk content if present (even on completion for error messages)
            if (!empty($event->chunk)) {
                $this->sendMessageChunk($response, $event);
            }

            // If not complete, we're done - don't process further
            if (!$event->isComplete) {
                return;
            }
            // If complete, continue to handleMessageStreaming for completion signal
        }

        // Handle ChatUpdatedEvent
        if ($eventClass === 'ChatUpdatedEvent' && $event instanceof ChatUpdatedEvent) {
            // Handle redirect
            if ($event->redirectUrl !== null) {
                $this->sendExecuteScript($response, "window.location.href = '{$event->redirectUrl}'");

                return;
            }

            // Handle message clearing
            if ($event->clearMessage) {
                $this->sendPatchSignals($response, ['_message' => '']);
            }

            // Handle message rendering
            if ($event->action === 'message_added' || $event->action === 'assistant_started') {
                $this->sendNewMessage($response, $event);

                return;
            }
        }

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

    private function sendNewMessage(SwooleHttpResponse $response, ChatUpdatedEvent $event): void {
        $html = $event->action === 'assistant_started'
            ? $this->renderAssistantPlaceholder($event)
            : $this->renderMessage($event);

        if (empty($html)) {
            return;
        }

        // Append the message to the #messages container
        $patchEvent = new PatchElements(
            $html,
            [
                'selector' => '#messages',
                'mode' => ElementPatchMode::Append,
            ]
        );

        $response->write($patchEvent->getOutput());

        // Auto-scroll to bottom after adding new message
        $this->sendExecuteScript($response, "requestAnimationFrame(() => { const c = document.getElementById('messages-container'); if (c) c.scrollTop = c.scrollHeight; })");
    }

    private function handleMessageStreaming(MessageStreamingEvent $event): ?string {
        if ($event->isComplete) {
            // Signal completion - triggers any client-side finalization
            return '<div id="message-' . $event->messageId . '-complete" data-streaming-complete="true"></div>';
        }

        // Return null - we'll handle this with append mode separately
        return null;
    }

    private function sendMessageChunk(SwooleHttpResponse $response, MessageStreamingEvent $event): void {
        if (empty($event->chunk)) {
            return;
        }

        // Escape HTML entities for safe rendering
        $escaped = htmlspecialchars($event->chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Use Datastar SDK with append mode to add content to the message
        $patchEvent = new PatchElements(
            '<span>' . $escaped . '</span>',
            [
                'selector' => '#message-' . $event->messageId . '-content',
                'mode' => ElementPatchMode::Append,
            ]
        );

        $response->write($patchEvent->getOutput());

        // Auto-scroll to bottom (using requestAnimationFrame for smooth throttling)
        $this->sendExecuteScript($response, "requestAnimationFrame(() => { const c = document.getElementById('messages-container'); if (c) c.scrollTop = c.scrollHeight; })");
    }

    private function handleChatUpdated(ChatUpdatedEvent $event): string {
        return match ($event->action) {
            'message_added' => $this->renderMessage($event),
            'assistant_started' => $this->renderAssistantPlaceholder($event),
            'title_updated' => $this->renderTitleUpdate($event),
            default => '<div id="chat-update-signal" data-chat-id="' . $event->chatId . '" data-action="' . $event->action . '"></div>',
        };
    }

    private function renderMessage(ChatUpdatedEvent $event): string {
        if ($event->messageId === null || $event->messageRole === null) {
            return '';
        }

        return $this->renderer->render('partials::message', [
            'id' => $event->messageId,
            'role' => $event->messageRole,
            'content' => $event->messageContent ?? '',
            'chatId' => $event->chatId,
            'streaming' => false,
            'e' => fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ]);
    }

    private function renderAssistantPlaceholder(ChatUpdatedEvent $event): string {
        if ($event->messageId === null) {
            return '';
        }

        return $this->renderer->render('partials::message', [
            'id' => $event->messageId,
            'role' => 'assistant',
            'content' => '',
            'chatId' => $event->chatId,
            'streaming' => true,
            'e' => fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ]);
    }

    private function renderTitleUpdate(ChatUpdatedEvent $event): string {
        $title = htmlspecialchars($event->title ?? 'New Chat', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return <<<HTML
<span id="chat-title">{$title}</span>
<a id="chat-link-{$event->chatId}" href="/chat/{$event->chatId}" class="chat-link" data-patch-mode="replace">
    <span class="chat-title">{$title}</span>
</a>
HTML;
    }

    private function handleDocumentUpdated(DocumentUpdatedEvent $event): string {
        return '<div id="document-update-signal" data-document-id="' . $event->documentId . '" data-action="' . $event->action . '"></div>';
    }

    private function sendDatastarFragment(SwooleHttpResponse $response, string $html): void {
        if (!$response->isWritable()) {
            return;
        }

        // Use Datastar SDK's MergeFragments event
        $event = new PatchElements($html);
        $response->write($event->getOutput());
    }

    private function sendExecuteScript(SwooleHttpResponse $response, string $script): void {
        if (!$response->isWritable()) {
            return;
        }

        $event = new ExecuteScript($script);
        $response->write($event->getOutput());
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function sendPatchSignals(SwooleHttpResponse $response, array $signals): void {
        if (!$response->isWritable()) {
            return;
        }

        $event = new PatchSignals($signals);
        $response->write($event->getOutput());
    }
}
