<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use App\Domain\Event\SuggestionsUpdatedEvent;
use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\SuggestionRepositoryInterface;
use App\Infrastructure\EventBus\EventBusInterface;

/**
 * AI tool that asks a cheap model for writing suggestions on a text document of the current chat.
 */
final class RequestSuggestionsTool implements ToolInterface {
    public const int MAX_SUGGESTIONS = 5;

    /** Longer documents are cut before they are sent to the suggestion model. */
    public const int MAX_CONTENT_CHARS = 20000;

    public const string SYSTEM_PROMPT = <<<'PROMPT'
You are a helpful writing assistant. Given a piece of writing, offer up to 5 suggestions to improve it and describe each change.
Each edit must replace a full sentence, not single words. Copy "originalSentence" verbatim from the text, including punctuation and markdown.
Respond with only a JSON array, no prose, in this shape:
[{"originalSentence": "...", "suggestedSentence": "...", "description": "..."}]
PROMPT;

    /**
     * @param \Closure(string, string): string $complete Model call: (system prompt, user prompt) to response text
     */
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly SuggestionRepositoryInterface $suggestionRepository,
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly EventBusInterface $eventBus,
        private readonly \Closure $complete,
        private readonly string $chatId,
    ) {}

    public function name(): string {
        return 'requestSuggestions';
    }

    public function description(): string {
        return 'Request writing suggestions for an existing text document of this chat. The suggestions appear in the document panel, where the user can accept or dismiss each one. Only use this when the user asks to improve or get suggestions for a document.';
    }

    public function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'documentId' => [
                    'type' => 'string',
                    'description' => 'The ID of the text document. Omit it to use the most recently created text document of this chat.',
                ],
            ],
        ];
    }

    public function execute(array $input): string {
        $documentId = mb_trim((string) ($input['documentId'] ?? ''));
        $document = $documentId === '' ? $this->latestTextDocument() : $this->documentRepository->findWithContent($documentId);

        if ($document === null || $document->chatId !== $this->chatId) {
            return $documentId === ''
                ? 'Error: This chat has no text document.'
                : "Error: Document with ID '{$documentId}' not found. " . $this->listDocuments();
        }

        if (!$document->isText()) {
            return "Error: Suggestions are only available for text documents, '{$document->title}' is a {$document->kind} document.";
        }

        $content = $document->content ?? '';
        if (mb_trim($content) === '') {
            return "Error: Document '{$document->title}' is empty.";
        }

        try {
            $response = ($this->complete)(self::SYSTEM_PROMPT, mb_substr($content, 0, self::MAX_CONTENT_CHARS));
        } catch (\Throwable $e) {
            error_log('Suggestion generation failed: ' . $e->getMessage());

            return 'Error: Could not generate suggestions.';
        }

        $suggestions = [];
        foreach (self::parseSuggestions($response) as $item) {
            $suggestion = Suggestion::create($document->id, $item['originalSentence'], $item['suggestedSentence'], $item['description']);
            // An edit that does not quote the document cannot be applied
            if ($suggestion->appliesTo($content)) {
                $suggestions[] = $suggestion;
            }
        }

        $this->suggestionRepository->replacePending($document->id, $suggestions);

        $chat = $this->chatRepository->find($this->chatId);
        if ($chat !== null) {
            $this->eventBus->emit($chat->userId, new SuggestionsUpdatedEvent(
                documentId: $document->id,
                chatId: $this->chatId,
                userId: $chat->userId,
                action: SuggestionsUpdatedEvent::ACTION_REQUESTED,
            ));
        }

        if ($suggestions === []) {
            return "No suggestions for document '{$document->title}': the text needs no changes.";
        }

        return \sprintf(
            "Added %d suggestion%s to document '%s'. The user can accept or dismiss them in the document panel; do not repeat them.",
            \count($suggestions),
            \count($suggestions) === 1 ? '' : 's',
            $document->title,
        );
    }

    /**
     * Extracts suggestion objects from a model response. Tolerates code fences, surrounding prose,
     * a {"suggestions": [...]} wrapper and a response cut off by the token limit.
     *
     * @return list<array{originalSentence: string, suggestedSentence: string, description: string}>
     */
    public static function parseSuggestions(string $response): array {
        $items = [];
        $seen = [];

        foreach (self::decodeJsonObjects($response) as $object) {
            $candidates = \is_array($object['suggestions'] ?? null) ? $object['suggestions'] : [$object];

            foreach ($candidates as $candidate) {
                if (!\is_array($candidate)) {
                    continue;
                }

                $original = \is_string($candidate['originalSentence'] ?? null) ? mb_trim($candidate['originalSentence']) : '';
                $suggested = \is_string($candidate['suggestedSentence'] ?? null) ? mb_trim($candidate['suggestedSentence']) : '';
                $description = \is_string($candidate['description'] ?? null) ? mb_trim($candidate['description']) : '';

                if ($original === '' || $suggested === '' || $description === '' || $original === $suggested || isset($seen[$original])) {
                    continue;
                }

                $seen[$original] = true;
                $items[] = ['originalSentence' => $original, 'suggestedSentence' => $suggested, 'description' => $description];

                if (\count($items) === self::MAX_SUGGESTIONS) {
                    return $items;
                }
            }
        }

        return $items;
    }

    /**
     * Decodes every complete top-level {...} object in the text, skipping anything else.
     *
     * @return list<array<mixed>>
     */
    private static function decodeJsonObjects(string $text): array {
        $objects = [];
        $depth = 0;
        $start = 0;
        $inString = false;
        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];

            if ($inString) {
                if ($char === '\\') {
                    ++$i;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"' && $depth > 0) {
                $inString = true;
            } elseif ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                ++$depth;
            } elseif ($char === '}' && $depth > 0) {
                --$depth;
                if ($depth === 0) {
                    $decoded = json_decode(substr($text, $start, $i - $start + 1), true);
                    if (\is_array($decoded)) {
                        $objects[] = $decoded;
                    }
                }
            }
        }

        return $objects;
    }

    private function latestTextDocument(): ?Document {
        foreach ($this->documentRepository->findByChat($this->chatId) as $document) {
            if ($document->isText()) {
                return $this->documentRepository->findWithContent($document->id);
            }
        }

        return null;
    }

    private function listDocuments(): string {
        $documents = array_map(
            static fn (Document $d): string => "{$d->id} ({$d->kind}: {$d->title})",
            $this->documentRepository->findByChat($this->chatId),
        );

        return $documents === [] ? 'This chat has no documents.' : 'Documents in this chat: ' . implode(', ', $documents);
    }
}
