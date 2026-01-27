<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Ramsey\Uuid\Uuid;

final class Chat {
    public function __construct(
        public readonly string $id,
        public readonly int $userId,
        public ?string $title,
        public string $model,
        public string $visibility,
        public readonly \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}

    public static function create(
        int $userId,
        string $model = 'claude-3-5-sonnet',
        string $visibility = 'private',
        ?string $title = null,
    ): self {
        $now = new \DateTimeImmutable();

        return new self(
            id: Uuid::uuid4()->toString(),
            userId: $userId,
            title: $title,
            model: $model,
            visibility: $visibility,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function updateTitle(string $title): self {
        return new self(
            id: $this->id,
            userId: $this->userId,
            title: $title,
            model: $this->model,
            visibility: $this->visibility,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function updateVisibility(string $visibility): self {
        return new self(
            id: $this->id,
            userId: $this->userId,
            title: $this->title,
            model: $this->model,
            visibility: $visibility,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function isOwnedBy(int $userId): bool {
        return $this->userId === $userId;
    }

    public function isPublic(): bool {
        return $this->visibility === 'public';
    }

    /**
     * @return array{id: string, userId: int, title: ?string, model: string, visibility: string, createdAt: int, updatedAt: int}
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'title' => $this->title,
            'model' => $this->model,
            'visibility' => $this->visibility,
            'createdAt' => $this->createdAt->getTimestamp(),
            'updatedAt' => $this->updatedAt->getTimestamp(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        return new self(
            id: $data['id'],
            userId: (int) $data['user_id'],
            title: $data['title'],
            model: $data['model'],
            visibility: $data['visibility'],
            createdAt: (new \DateTimeImmutable())->setTimestamp((int) $data['created_at']),
            updatedAt: (new \DateTimeImmutable())->setTimestamp((int) $data['updated_at']),
        );
    }
}
