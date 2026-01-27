<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Model\Document;
use App\Domain\Repository\DocumentRepositoryInterface;

final class SqliteDocumentRepository implements DocumentRepositoryInterface {
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function find(string $id): ?Document {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return Document::fromArray($row);
    }

    /**
     * @return list<Document>
     */
    public function findByChat(string $chatId): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM documents WHERE chat_id = :chat_id ORDER BY created_at DESC'
        );
        $stmt->execute(['chat_id' => $chatId]);

        $documents = [];
        while ($row = $stmt->fetch()) {
            $documents[] = Document::fromArray($row);
        }

        return $documents;
    }

    public function findWithContent(string $id, ?int $version = null): ?Document {
        $document = $this->find($id);

        if ($document === null) {
            return null;
        }

        if ($version !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT content, version FROM document_versions
                 WHERE document_id = :document_id AND version = :version'
            );
            $stmt->execute(['document_id' => $id, 'version' => $version]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT content, version FROM document_versions
                 WHERE document_id = :document_id ORDER BY version DESC LIMIT 1'
            );
            $stmt->execute(['document_id' => $id]);
        }

        $row = $stmt->fetch();

        if ($row === false) {
            return $document;
        }

        return new Document(
            id: $document->id,
            chatId: $document->chatId,
            messageId: $document->messageId,
            kind: $document->kind,
            title: $document->title,
            language: $document->language,
            createdAt: $document->createdAt,
            updatedAt: $document->updatedAt,
            content: $row['content'],
            currentVersion: (int) $row['version'],
        );
    }

    public function save(Document $document): void {
        $existing = $this->find($document->id);

        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO documents (id, chat_id, message_id, kind, title, language, created_at, updated_at)
                 VALUES (:id, :chat_id, :message_id, :kind, :title, :language, :created_at, :updated_at)'
            );

            $stmt->execute([
                'id' => $document->id,
                'chat_id' => $document->chatId,
                'message_id' => $document->messageId,
                'kind' => $document->kind,
                'title' => $document->title,
                'language' => $document->language,
                'created_at' => $document->createdAt->getTimestamp(),
                'updated_at' => $document->updatedAt->getTimestamp(),
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE documents SET title = :title, language = :language, updated_at = :updated_at
                 WHERE id = :id'
            );

            $stmt->execute([
                'id' => $document->id,
                'title' => $document->title,
                'language' => $document->language,
                'updated_at' => $document->updatedAt->getTimestamp(),
            ]);
        }

        // Save version if content is provided
        if ($document->content !== null) {
            $this->saveVersion($document);
        }
    }

    public function saveVersion(Document $document): void {
        // Get the next version number
        $stmt = $this->pdo->prepare(
            'SELECT MAX(version) as max_version FROM document_versions WHERE document_id = :document_id'
        );
        $stmt->execute(['document_id' => $document->id]);
        $row = $stmt->fetch();
        $nextVersion = ($row['max_version'] ?? 0) + 1;

        $stmt = $this->pdo->prepare(
            'INSERT INTO document_versions (document_id, content, version, created_at)
             VALUES (:document_id, :content, :version, :created_at)'
        );

        $stmt->execute([
            'document_id' => $document->id,
            'content' => $document->content ?? '',
            'version' => $nextVersion,
            'created_at' => time(),
        ]);
    }

    /**
     * @return list<array{version: int, createdAt: int}>
     */
    public function getVersions(string $documentId): array {
        $stmt = $this->pdo->prepare(
            'SELECT version, created_at AS createdAt FROM document_versions
             WHERE document_id = :document_id ORDER BY version DESC'
        );
        $stmt->execute(['document_id' => $documentId]);

        /** @var list<array{version: int, createdAt: int}> */
        return $stmt->fetchAll();
    }

    public function delete(string $id): void {
        // Versions are deleted via cascade
        $stmt = $this->pdo->prepare('DELETE FROM documents WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteByChat(string $chatId): void {
        $stmt = $this->pdo->prepare('DELETE FROM documents WHERE chat_id = :chat_id');
        $stmt->execute(['chat_id' => $chatId]);
    }
}
