<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

/**
 * AI Tool for requesting writing suggestions for a document.
 *
 * This tool allows the AI to generate suggested improvements
 * for document content that the user can accept or reject.
 */
final class RequestSuggestionsTool {
    /**
     * Request suggestions for improving document content.
     *
     * @param string $documentId  The ID of the document to get suggestions for
     * @param string $description What kind of suggestions to generate (e.g., 'grammar', 'clarity', 'style')
     *
     * @return string Acknowledgment that suggestions will be generated
     */
    public function requestSuggestions(
        string $documentId,
        string $description,
    ): string {
        // For now, return acknowledgment - actual implementation would queue suggestion generation
        return "Suggestions requested for document '{$documentId}'. Type: {$description}. Suggestions will be generated.";
    }
}
