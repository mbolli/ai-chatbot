<?php

declare(strict_types=1);

namespace App\Domain\Model;

final class Vote {
    public function __construct(
        public readonly ?int $id,
        public readonly string $chatId,
        public readonly string $messageId,
        public readonly int $userId,
        public readonly bool $isUpvote,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function upvote(string $chatId, string $messageId, int $userId): self {
        return new self(
            id: null,
            chatId: $chatId,
            messageId: $messageId,
            userId: $userId,
            isUpvote: true,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public static function downvote(string $chatId, string $messageId, int $userId): self {
        return new self(
            id: null,
            chatId: $chatId,
            messageId: $messageId,
            userId: $userId,
            isUpvote: false,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function toggle(): self {
        return new self(
            id: $this->id,
            chatId: $this->chatId,
            messageId: $this->messageId,
            userId: $this->userId,
            isUpvote: !$this->isUpvote,
            createdAt: $this->createdAt,
        );
    }

    /**
     * @return array{id: ?int, chatId: string, messageId: string, userId: int, isUpvote: bool, createdAt: int}
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'chatId' => $this->chatId,
            'messageId' => $this->messageId,
            'userId' => $this->userId,
            'isUpvote' => $this->isUpvote,
            'createdAt' => $this->createdAt->getTimestamp(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            chatId: $data['chat_id'],
            messageId: $data['message_id'],
            userId: (int) $data['user_id'],
            isUpvote: (bool) $data['is_upvote'],
            createdAt: (new \DateTimeImmutable())->setTimestamp((int) $data['created_at']),
        );
    }
}
