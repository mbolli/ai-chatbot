<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Message;
use App\Domain\Model\MessageUsage;

interface MessageRepositoryInterface {
    public function find(string $id): ?Message;

    /**
     * @return list<Message>
     */
    public function findByChat(string $chatId): array;

    public function save(Message $message): void;

    public function update(Message $message): void;

    public function delete(string $id): void;

    public function deleteByChat(string $chatId): void;

    public function saveUsage(MessageUsage $usage): void;

    public function findUsage(string $messageId): ?MessageUsage;
}
