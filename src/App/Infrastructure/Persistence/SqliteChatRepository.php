<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Model\Chat;
use App\Domain\Repository\ChatRepositoryInterface;

final class SqliteChatRepository implements ChatRepositoryInterface {
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function find(string $id): ?Chat {
        $stmt = $this->pdo->prepare('SELECT * FROM chats WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return Chat::fromArray($row);
    }

    /**
     * @return list<Chat>
     */
    public function findByUser(int $userId, int $limit = 50, int $offset = 0): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM chats WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $chats = [];
        while ($row = $stmt->fetch()) {
            $chats[] = Chat::fromArray($row);
        }

        return $chats;
    }

    public function save(Chat $chat): void {
        $existing = $this->find($chat->id);

        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chats (id, user_id, title, model, visibility, created_at, updated_at)
                 VALUES (:id, :user_id, :title, :model, :visibility, :created_at, :updated_at)'
            );
            $stmt->execute([
                'id' => $chat->id,
                'user_id' => $chat->userId,
                'title' => $chat->title,
                'model' => $chat->model,
                'visibility' => $chat->visibility,
                'created_at' => $chat->createdAt->getTimestamp(),
                'updated_at' => $chat->updatedAt->getTimestamp(),
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE chats SET title = :title, model = :model, visibility = :visibility, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $chat->id,
                'title' => $chat->title,
                'model' => $chat->model,
                'visibility' => $chat->visibility,
                'updated_at' => $chat->updatedAt->getTimestamp(),
            ]);
        }
    }

    public function delete(string $id): void {
        $stmt = $this->pdo->prepare('DELETE FROM chats WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteByUser(int $userId): void {
        $stmt = $this->pdo->prepare('DELETE FROM chats WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }
}
