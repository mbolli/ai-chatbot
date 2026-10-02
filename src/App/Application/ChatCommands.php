<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Message;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Infrastructure\EventBus\EventBusInterface;

/**
 * Chat lifecycle commands. Methods return an HTTP-like status code.
 */
final class ChatCommands {
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly EventBusInterface $eventBus,
        private readonly AIServiceInterface $aiService,
    ) {}

    /**
     * Create a chat, optionally with its first user message.
     */
    public function create(int $userId, ?string $model = null, ?string $initialMessage = null): Chat {
        $available = $this->aiService->getAvailableModels();
        if ($model === null || !($available[$model]['available'] ?? false)) {
            $model = $this->aiService->getDefaultModel();
        }

        $chat = Chat::create(userId: $userId, model: $model);
        $this->chatRepository->save($chat);

        if ($initialMessage !== null && mb_trim($initialMessage) !== '') {
            $this->messageRepository->save(Message::user($chat->id, $initialMessage));
        }

        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chat->id,
            userId: $userId,
            action: 'created',
        ));

        return $chat;
    }

    public function delete(int $userId, string $chatId): int {
        $status = $this->ownedChat($userId, $chatId);
        if ($status !== 200) {
            return $status;
        }

        $this->chatRepository->delete($chatId);

        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'deleted',
        ));

        return 204;
    }

    public function visibility(int $userId, string $chatId, string $visibility): int {
        if (!\in_array($visibility, ['private', 'public'], true)) {
            return 400;
        }

        $chat = $this->chatRepository->find($chatId);
        if ($chat === null) {
            return 404;
        }
        if (!$chat->isOwnedBy($userId)) {
            return 403;
        }

        $this->chatRepository->save($chat->updateVisibility($visibility));

        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'visibility_changed',
        ));

        return 204;
    }

    public function model(int $userId, string $chatId, string $model): int {
        if (!($this->aiService->getAvailableModels()[$model]['available'] ?? false)) {
            return 400;
        }

        $chat = $this->chatRepository->find($chatId);
        if ($chat === null) {
            return 404;
        }
        if (!$chat->isOwnedBy($userId)) {
            return 403;
        }

        $this->chatRepository->save($chat->updateModel($model));

        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'model_changed',
        ));

        return 204;
    }

    private function ownedChat(int $userId, string $chatId): int {
        $chat = $this->chatRepository->find($chatId);
        if ($chat === null) {
            return 404;
        }

        return $chat->isOwnedBy($userId) ? 200 : 403;
    }
}
