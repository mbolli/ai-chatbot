<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Ramsey\Uuid\Uuid;

final class Document {
    public const string KIND_TEXT = 'text';
    public const string KIND_CODE = 'code';
    public const string KIND_SHEET = 'sheet';
    public const string KIND_IMAGE = 'image';

    public function __construct(
        public readonly string $id,
        public readonly string $chatId,
        public readonly ?string $messageId,
        public readonly string $kind,
        public string $title,
        public ?string $language,
        public readonly \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?string $content = null,
        public int $currentVersion = 1,
    ) {}

    public static function create(
        string $chatId,
        string $kind,
        string $title,
        string $content = '',
        ?string $messageId = null,
        ?string $language = null,
    ): self {
        $now = new \DateTimeImmutable();

        return new self(
            id: Uuid::uuid4()->toString(),
            chatId: $chatId,
            messageId: $messageId,
            kind: $kind,
            title: $title,
            language: $language,
            createdAt: $now,
            updatedAt: $now,
            content: $content,
            currentVersion: 1,
        );
    }

    public static function text(string $chatId, string $title, string $content = '', ?string $messageId = null): self {
        return self::create($chatId, self::KIND_TEXT, $title, $content, $messageId);
    }

    public static function code(
        string $chatId,
        string $title,
        string $content = '',
        string $language = 'python',
        ?string $messageId = null,
    ): self {
        return self::create($chatId, self::KIND_CODE, $title, $content, $messageId, $language);
    }

    public static function sheet(string $chatId, string $title, string $content = '', ?string $messageId = null): self {
        return self::create($chatId, self::KIND_SHEET, $title, $content, $messageId);
    }

    public static function image(string $chatId, string $title, string $content = '', ?string $messageId = null): self {
        return self::create($chatId, self::KIND_IMAGE, $title, $content, $messageId);
    }

    public function updateContent(string $content): self {
        return new self(
            id: $this->id,
            chatId: $this->chatId,
            messageId: $this->messageId,
            kind: $this->kind,
            title: $this->title,
            language: $this->language,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
            content: $content,
            currentVersion: $this->currentVersion + 1,
        );
    }

    public function updateTitle(string $title): self {
        return new self(
            id: $this->id,
            chatId: $this->chatId,
            messageId: $this->messageId,
            kind: $this->kind,
            title: $title,
            language: $this->language,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
            content: $this->content,
            currentVersion: $this->currentVersion,
        );
    }

    public function isText(): bool {
        return $this->kind === self::KIND_TEXT;
    }

    public function isCode(): bool {
        return $this->kind === self::KIND_CODE;
    }

    public function isSheet(): bool {
        return $this->kind === self::KIND_SHEET;
    }

    public function isImage(): bool {
        return $this->kind === self::KIND_IMAGE;
    }

    /**
     * @return array{id: string, chatId: string, messageId: ?string, kind: string, title: string, language: ?string, content: ?string, currentVersion: int, createdAt: int, updatedAt: int}
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'chatId' => $this->chatId,
            'messageId' => $this->messageId,
            'kind' => $this->kind,
            'title' => $this->title,
            'language' => $this->language,
            'content' => $this->content,
            'currentVersion' => $this->currentVersion,
            'createdAt' => $this->createdAt->getTimestamp(),
            'updatedAt' => $this->updatedAt->getTimestamp(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        return new self(
            id: $data['id'],
            chatId: $data['chat_id'],
            messageId: $data['message_id'] ?? null,
            kind: $data['kind'],
            title: $data['title'],
            language: $data['language'] ?? null,
            createdAt: (new \DateTimeImmutable())->setTimestamp((int) $data['created_at']),
            updatedAt: (new \DateTimeImmutable())->setTimestamp((int) $data['updated_at']),
            content: $data['content'] ?? null,
            currentVersion: (int) ($data['current_version'] ?? $data['version'] ?? 1),
        );
    }
}
