<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Model\Suggestion;
use App\Domain\Repository\SuggestionRepositoryInterface;

final class SqliteSuggestionRepository implements SuggestionRepositoryInterface {
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function find(string $id): ?Suggestion {
        $stmt = $this->pdo->prepare('SELECT * FROM suggestions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return Suggestion::fromArray($row);
    }

    /**
     * @return list<Suggestion>
     */
    public function findPendingByDocument(string $documentId): array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM suggestions WHERE document_id = :document_id AND status = 'pending' ORDER BY created_at, rowid"
        );
        $stmt->execute(['document_id' => $documentId]);

        $suggestions = [];
        while ($row = $stmt->fetch()) {
            $suggestions[] = Suggestion::fromArray($row);
        }

        return $suggestions;
    }

    /**
     * @param list<Suggestion> $suggestions
     */
    public function replacePending(string $documentId, array $suggestions): void {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("DELETE FROM suggestions WHERE document_id = :document_id AND status = 'pending'");
            $stmt->execute(['document_id' => $documentId]);

            foreach ($suggestions as $suggestion) {
                $this->save($suggestion);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function save(Suggestion $suggestion): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suggestions (id, document_id, content, status, created_at)
             VALUES (:id, :document_id, :content, :status, :created_at)
             ON CONFLICT (id) DO UPDATE SET content = excluded.content, status = excluded.status'
        );

        $stmt->execute([
            'id' => $suggestion->id,
            'document_id' => $suggestion->documentId,
            'content' => $suggestion->contentJson(),
            'status' => $suggestion->status,
            'created_at' => $suggestion->createdAt->getTimestamp(),
        ]);
    }
}
