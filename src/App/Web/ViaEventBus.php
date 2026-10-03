<?php

declare(strict_types=1);

namespace App\Web;

use App\Domain\Event\AccountChangedEvent;
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

/**
 * Turns domain events into state changes plus php-via broadcasts; the components re-render from state.
 */
final class ViaEventBus implements EventBusInterface {
    private ?Via $app = null;

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
            $event instanceof DocumentUpdatedEvent => $this->onDocumentUpdated($event),
            $event instanceof SuggestionsUpdatedEvent => $this->onSuggestionsUpdated($event),
            $event instanceof VoteUpdatedEvent => $this->broadcast(Scopes::chat($event->chatId)),
            $event instanceof RateLimitExceededEvent => $this->onRateLimited($event),
            $event instanceof AccountChangedEvent => $this->broadcast(Scopes::user($event->userId)),
            default => null,
        };
    }

    private function onStreaming(MessageStreamingEvent $event): void {
        if (!$event->isComplete) {
            $this->state->updateStream($event->chatId, $event->messageId, content: $event->fullContent);
            $this->broadcast(Scopes::stream($event->chatId));

            return;
        }

        // The message list renders the final content from the database
        $this->state->endStream($event->chatId);
        $this->broadcast(Scopes::chat($event->chatId));
    }

    private function onThinking(MessageThinkingEvent $event): void {
        $this->state->updateStream($event->chatId, $event->messageId, thinking: $event->fullThinking);
        $this->broadcast(Scopes::stream($event->chatId));
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

    private function onDocumentUpdated(DocumentUpdatedEvent $event): void {
        // A document the reply creates or changes opens in the chat's tabs; a user's own edit leaves them be
        if ($event->action === 'created' || ($event->action === 'updated' && $this->state->stream($event->chatId) !== null)) {
            $this->state->requestDocument($event->chatId, $event->documentId);
        }

        $this->broadcast(Scopes::chat($event->chatId));
    }

    private function onSuggestionsUpdated(SuggestionsUpdatedEvent $event): void {
        if ($event->action === SuggestionsUpdatedEvent::ACTION_REQUESTED) {
            $this->state->requestDocument($event->chatId, $event->documentId);
        }

        $this->broadcast(Scopes::chat($event->chatId));
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

        // The refused message never starts a reply, so the input leaves its generating state
        $signals = ['_generatingMessage' => ''];
        if ($event->isGuest) {
            $signals['_authModal'] = 'upgrade';
        }

        $this->state->pushToast($event->userId, $message, signals: $signals);
        $this->broadcast(Scopes::user($event->userId));
    }

    private function broadcast(string $scope): void {
        $this->app?->broadcast($scope);
    }
}
