<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use App\Domain\Model\Document;
use App\Domain\Repository\DocumentRepositoryInterface;

/**
 * AI Tool for updating existing documents (artifacts).
 *
 * This tool allows the AI to modify the content of existing documents.
 * Each update creates a new version for undo/redo functionality.
 */
final class UpdateDocumentTool implements ToolInterface {
    private ?Document $lastUpdatedDocument = null;

    /**
     * @param null|string $chatId Restricts updates to documents of this chat
     */
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly ?string $chatId = null,
    ) {}

    public function getLastUpdatedDocument(): ?Document {
        return $this->lastUpdatedDocument;
    }

    public function name(): string {
        return 'updateDocument';
    }

    public function description(): string {
        return 'Update an existing document artifact with new content. Replaces the full content and creates a new version.';
    }

    public function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'documentId' => [
                    'type' => 'string',
                    'description' => 'The ID of the document to update',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The new full content for the document',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional new title',
                ],
            ],
            'required' => ['documentId', 'content'],
        ];
    }

    public function execute(array $input): string {
        return $this->updateDocument(
            (string) ($input['documentId'] ?? ''),
            (string) ($input['content'] ?? ''),
            isset($input['title']) ? (string) $input['title'] : null,
        );
    }

    /**
     * Update an existing document's content.
     *
     * @param string      $documentId The ID of the document to update
     * @param string      $content    The new content for the document
     * @param null|string $title      Optional new title for the document
     *
     * @return string A confirmation message or error
     */
    public function updateDocument(
        string $documentId,
        string $content,
        ?string $title = null,
    ): string {
        $document = $this->documentRepository->findWithContent($documentId);

        if ($document === null || ($this->chatId !== null && $document->chatId !== $this->chatId)) {
            return "Error: Document with ID '{$documentId}' not found.";
        }

        // Update content (creates new version)
        $updatedDocument = $document->updateContent($content);

        // Update title if provided
        if ($title !== null) {
            $updatedDocument = $updatedDocument->updateTitle($title);
        }

        // Save the updated document
        $this->documentRepository->save($updatedDocument);
        $this->lastUpdatedDocument = $updatedDocument;

        return "Document '{$document->title}' updated successfully. New version: {$updatedDocument->currentVersion}";
    }
}
