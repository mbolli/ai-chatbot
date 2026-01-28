<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use App\Domain\Model\Document;
use App\Domain\Repository\DocumentRepositoryInterface;

/**
 * AI Tool for creating new documents (artifacts).
 *
 * This tool allows the AI to create text documents, code snippets,
 * spreadsheets, or images that appear in the artifact panel.
 */
final class CreateDocumentTool {
    private ?string $chatId = null;
    private ?string $messageId = null;
    private ?Document $lastCreatedDocument = null;

    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
    ) {}

    public function setChatContext(string $chatId, ?string $messageId = null): void {
        $this->chatId = $chatId;
        $this->messageId = $messageId;
    }

    public function getLastCreatedDocument(): ?Document {
        return $this->lastCreatedDocument;
    }

    /**
     * Create a new document artifact.
     *
     * @param string $kind The type of document: 'text' for markdown/prose, 'code' for programming code, 'sheet' for CSV data, 'image' for SVG/image content
     * @param string $title A short descriptive title for the document
     * @param string $content The actual content of the document
     * @param string|null $language For code documents, the programming language (e.g., 'python', 'javascript', 'php')
     *
     * @return string A confirmation message with the document ID
     */
    public function createDocument(
        string $kind,
        string $title,
        string $content,
        ?string $language = null,
    ): string {
        if ($this->chatId === null) {
            return 'Error: Chat context not set. Cannot create document.';
        }

        // Validate kind
        $validKinds = [Document::KIND_TEXT, Document::KIND_CODE, Document::KIND_SHEET, Document::KIND_IMAGE];
        if (!\in_array($kind, $validKinds, true)) {
            return "Error: Invalid document kind '{$kind}'. Must be one of: " . implode(', ', $validKinds);
        }

        // Create the document
        $document = match ($kind) {
            Document::KIND_TEXT => Document::text($this->chatId, $title, $content, $this->messageId),
            Document::KIND_CODE => Document::code($this->chatId, $title, $content, $language ?? 'python', $this->messageId),
            Document::KIND_SHEET => Document::sheet($this->chatId, $title, $content, $this->messageId),
            Document::KIND_IMAGE => Document::image($this->chatId, $title, $content, $this->messageId),
        };

        // Save to repository
        $this->documentRepository->save($document);
        $this->lastCreatedDocument = $document;

        return "Document created successfully with ID: {$document->id}";
    }
}
