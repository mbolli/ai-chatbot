<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Document;

interface DocumentRepositoryInterface {
    public function find(string $id): ?Document;

    public function findByMessageId(string $messageId): ?Document;

    /**
     * @return list<Document>
     */
    public function findByChat(string $chatId): array;

    public function findWithContent(string $id, ?int $version = null): ?Document;

    public function save(Document $document): void;

    public function saveVersion(Document $document): void;

    /**
     * @return list<array{version: int, createdAt: int}>
     */
    public function getVersions(string $documentId): array;

    public function delete(string $id): void;

    public function deleteByChat(string $chatId): void;
}
