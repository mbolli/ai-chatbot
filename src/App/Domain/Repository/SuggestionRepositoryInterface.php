<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Suggestion;

interface SuggestionRepositoryInterface {
    public function find(string $id): ?Suggestion;

    /**
     * @return list<Suggestion> Oldest first
     */
    public function findPendingByDocument(string $documentId): array;

    /**
     * Replaces all pending suggestions of a document with the given ones.
     *
     * @param list<Suggestion> $suggestions
     */
    public function replacePending(string $documentId, array $suggestions): void;

    public function save(Suggestion $suggestion): void;
}
