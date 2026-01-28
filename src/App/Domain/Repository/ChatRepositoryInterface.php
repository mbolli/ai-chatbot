<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Chat;

interface ChatRepositoryInterface {
    public function find(string $id): ?Chat;

    /**
     * @return list<Chat>
     */
    public function findByUser(int $userId, int $limit = 50, int $offset = 0): array;

    public function save(Chat $chat): void;

    public function update(Chat $chat): void;

    public function delete(string $id): void;

    public function deleteByUser(int $userId): void;
}
