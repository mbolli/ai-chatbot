<?php

declare(strict_types=1);

namespace App\Web;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\MessageStreamingEvent;
use App\Domain\Event\MessageThinkingEvent;
use App\Domain\Event\RateLimitExceededEvent;
use App\Domain\Event\SuggestionsUpdatedEvent;
use App\Domain\Event\VoteUpdatedEvent;
use App\Domain\Service\RateLimitType;
use App\Infrastructure\EventBus\EventBusInterface;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

/**
 * Turns domain events into state changes plus php-via broadcasts; the components re-render from state.
 */
final class ViaEventBus implements EventBusInterface {
    private const int STREAM_INTERVAL_MS = 50;

    private ?Via $app = null;

    /** @var array<string, float> */
    private array $lastStreamBroadcast = [];

    /** @var array<string, true> */
    private array $pendingStreamBroadcast = [];

    public function __construct(private readonly LiveState $state) {}

    /**
     * The app is created after the services, so it is attached once it exists.
     */
    public function attach(Via $app): void {
        $this->app = $app;
    }

    public function emit(int $userId, object $event): void {
        match (true) {
            $event instanceof MessageStreamingEvent => $this->onStreaming($event),
            $event instanceof MessageThinkingEvent => $this->onThinking($event),
            $event instanceof ChatUpdatedEvent => $this->onChatUpdated($event),
            $event instanceof DocumentUpdatedEvent,
            $event instanceof SuggestionsUpdatedEvent,
            $event instanceof VoteUpdatedEvent => $this->broadcast(Scopes::chat($event->chatId)),
            $event instanceof RateLimitExceededEvent => $this->onRateLimited($event),
            default => null,
        };
    }

    private function onStreaming(MessageStreamingEvent $event): void {
        if (!$event->isComplete) {
            $this->state->updateStream($event->chatId, $event->messageId, content: $event->fullContent);
            $this->broadcastStream($event->chatId);

            return;
        }

        // The message list renders the final content from the database
        $this->state->endStream($event->chatId);
        $this->broadcast(Scopes::chat($event->chatId));
    }

    private function onThinking(MessageThinkingEvent $event): void {
        $this->state->updateStream($event->chatId, $event->messageId, thinking: $event->fullThinking);
        $this->broadcastStream($event->chatId);
    }

    private function onChatUpdated(ChatUpdatedEvent $event): void {
        if ($event->action === 'assistant_started' && $event->messageId !== null) {
            $this->state->startStream($event->chatId, $event->messageId);
        } elseif ($event->action === 'generation_stopped') {
            $this->state->endStream($event->chatId);
        }

        $this->broadcast(Scopes::chat($event->chatId));
        $this->broadcast(Scopes::user($event->userId));
    }

    private function onRateLimited(RateLimitExceededEvent $event): void {
        $message = match ($event->type) {
            RateLimitType::HourlyRequests => "You've sent {$event->limit} messages in the last hour. Please wait a bit before sending more.",
            RateLimitType::DailyTokens => "You've used today's AI budget. Limit resets at midnight.",
            RateLimitType::DailyMessages => "You've reached your daily limit of {$event->limit} messages. Limit resets at midnight.",
        };
        if ($event->isGuest) {
            $message .= ' Sign up for more!';
        }

        $this->state->pushToast($event->userId, $message);
        $this->broadcast(Scopes::user($event->userId));
    }

    /**
     * At most one stream broadcast per interval and chat; a trailing one carries the latest text.
     */
    private function broadcastStream(string $chatId): void {
        if (isset($this->pendingStreamBroadcast[$chatId])) {
            return;
        }

        $elapsedMs = (microtime(true) - ($this->lastStreamBroadcast[$chatId] ?? 0.0)) * 1000;
        if ($elapsedMs >= self::STREAM_INTERVAL_MS) {
            $this->lastStreamBroadcast[$chatId] = microtime(true);
            $this->broadcast(Scopes::stream($chatId));

            return;
        }

        $this->pendingStreamBroadcast[$chatId] = true;
        Timer::after((int) ceil(self::STREAM_INTERVAL_MS - $elapsedMs), function () use ($chatId): void {
            unset($this->pendingStreamBroadcast[$chatId]);
            $this->lastStreamBroadcast[$chatId] = microtime(true);
            $this->broadcast(Scopes::stream($chatId));
        });
    }

    private function broadcast(string $scope): void {
        $this->app?->broadcast($scope);
    }
}
