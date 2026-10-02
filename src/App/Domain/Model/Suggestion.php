<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Ramsey\Uuid\Uuid;

/**
 * A proposed replacement of one sentence in a text document.
 */
final class Suggestion {
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_ACCEPTED = 'accepted';
    public const string STATUS_REJECTED = 'rejected';

    public function __construct(
        public readonly string $id,
        public readonly string $documentId,
        public readonly string $originalText,
        public readonly string $suggestedText,
        public readonly string $description,
        public readonly string $status,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function create(string $documentId, string $originalText, string $suggestedText, string $description): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            documentId: $documentId,
            originalText: $originalText,
            suggestedText: $suggestedText,
            description: $description,
            status: self::STATUS_PENDING,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function isPending(): bool {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Whether the original text still occurs in the given content, i.e. the suggestion can be applied.
     */
    public function appliesTo(string $content): bool {
        return str_contains($content, $this->originalText);
    }

    /**
     * Replaces the first occurrence of the original text.
     */
    public function applyTo(string $content): string {
        $position = strpos($content, $this->originalText);

        if ($position === false) {
            return $content;
        }

        return substr_replace($content, $this->suggestedText, $position, \strlen($this->originalText));
    }

    public function accept(): self {
        return $this->withStatus(self::STATUS_ACCEPTED);
    }

    public function reject(): self {
        return $this->withStatus(self::STATUS_REJECTED);
    }

    /**
     * @return array{id: string, documentId: string, originalText: string, suggestedText: string, description: string, status: string, createdAt: int}
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'documentId' => $this->documentId,
            'originalText' => $this->originalText,
            'suggestedText' => $this->suggestedText,
            'description' => $this->description,
            'status' => $this->status,
            'createdAt' => $this->createdAt->getTimestamp(),
        ];
    }

    /**
     * @param array<string, mixed> $data Row of the suggestions table; the texts live in the JSON "content" column
     */
    public static function fromArray(array $data): self {
        $content = json_decode((string) ($data['content'] ?? ''), true);
        $content = \is_array($content) ? $content : [];

        return new self(
            id: $data['id'],
            documentId: $data['document_id'],
            originalText: (string) ($content['originalText'] ?? ''),
            suggestedText: (string) ($content['suggestedText'] ?? ''),
            description: (string) ($content['description'] ?? ''),
            status: $data['status'] ?? self::STATUS_PENDING,
            createdAt: (new \DateTimeImmutable())->setTimestamp((int) $data['created_at']),
        );
    }

    /**
     * JSON stored in the "content" column.
     */
    public function contentJson(): string {
        return json_encode([
            'originalText' => $this->originalText,
            'suggestedText' => $this->suggestedText,
            'description' => $this->description,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function withStatus(string $status): self {
        return new self(
            id: $this->id,
            documentId: $this->documentId,
            originalText: $this->originalText,
            suggestedText: $this->suggestedText,
            description: $this->description,
            status: $status,
            createdAt: $this->createdAt,
        );
    }
}
