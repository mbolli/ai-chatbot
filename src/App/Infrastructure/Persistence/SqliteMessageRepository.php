<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Model\Message;
use App\Domain\Repository\MessageRepositoryInterface;

final class SqliteMessageRepository implements MessageRepositoryInterface {
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function find(string $id): ?Message {
        $stmt = $this->pdo->prepare('SELECT * FROM messages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return Message::fromArray($row);
    }

    /**
     * @return list<Message>
     */
    public function findByChat(string $chatId): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM messages WHERE chat_id = :chat_id ORDER BY created_at ASC'
        );
        $stmt->execute(['chat_id' => $chatId]);

        $messages = [];
        while ($row = $stmt->fetch()) {
            $messages[] = Message::fromArray($row);
        }

        return $messages;
    }

    public function save(Message $message): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO messages (id, chat_id, role, content, parts, created_at)
             VALUES (:id, :chat_id, :role, :content, :parts, :created_at)'
        );

        $parts = $message->parts !== null ? json_encode($message->parts) : null;

        $stmt->execute([
            'id' => $message->id,
            'chat_id' => $message->chatId,
            'role' => $message->role,
            'content' => $message->content,
            'parts' => $parts,
            'created_at' => $message->createdAt->getTimestamp(),
        ]);
    }

    public function update(Message $message): void {
        $stmt = $this->pdo->prepare(
            'UPDATE messages SET content = :content, parts = :parts WHERE id = :id'
        );

        $parts = $message->parts !== null ? json_encode($message->parts) : null;

        $stmt->execute([
            'id' => $message->id,
            'content' => $message->content,
            'parts' => $parts,
        ]);
    }

    public function delete(string $id): void {
        $stmt = $this->pdo->prepare('DELETE FROM messages WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteByChat(string $chatId): void {
        $stmt = $this->pdo->prepare('DELETE FROM messages WHERE chat_id = :chat_id');
        $stmt->execute(['chat_id' => $chatId]);
    }
}
