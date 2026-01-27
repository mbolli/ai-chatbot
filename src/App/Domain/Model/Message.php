<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Ramsey\Uuid\Uuid;

final class Message {
    public const string ROLE_USER = 'user';
    public const string ROLE_ASSISTANT = 'assistant';
    public const string ROLE_SYSTEM = 'system';

    /**
     * @param null|list<array<string, mixed>> $parts
     */
    public function __construct(
        public readonly string $id,
        public readonly string $chatId,
        public readonly string $role,
        public string $content,
        public ?array $parts,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function user(string $chatId, string $content): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            chatId: $chatId,
            role: self::ROLE_USER,
            content: $content,
            parts: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    /**
     * @param null|list<array<string, mixed>> $parts
     */
    public static function assistant(string $chatId, string $content = '', ?array $parts = null): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            chatId: $chatId,
            role: self::ROLE_ASSISTANT,
            content: $content,
            parts: $parts,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public static function system(string $chatId, string $content): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            chatId: $chatId,
            role: self::ROLE_SYSTEM,
            content: $content,
            parts: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function appendContent(string $chunk): self {
        return new self(
            id: $this->id,
            chatId: $this->chatId,
            role: $this->role,
            content: $this->content . $chunk,
            parts: $this->parts,
            createdAt: $this->createdAt,
        );
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    public function withParts(array $parts): self {
        return new self(
            id: $this->id,
            chatId: $this->chatId,
            role: $this->role,
            content: $this->content,
            parts: $parts,
            createdAt: $this->createdAt,
        );
    }

    /**
     * @param array<string, mixed> $part
     */
    public function addPart(array $part): self {
        $parts = $this->parts ?? [];
        $parts[] = $part;

        return new self(
            id: $this->id,
            chatId: $this->chatId,
            role: $this->role,
            content: $this->content,
            parts: $parts,
            createdAt: $this->createdAt,
        );
    }

    public function isUser(): bool {
        return $this->role === self::ROLE_USER;
    }

    public function isAssistant(): bool {
        return $this->role === self::ROLE_ASSISTANT;
    }

    public function hasToolCalls(): bool {
        if ($this->parts === null) {
            return false;
        }

        foreach ($this->parts as $part) {
            if (($part['type'] ?? '') === 'tool-invocation') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getToolCalls(): array {
        if ($this->parts === null) {
            return [];
        }

        return array_values(array_filter($this->parts, fn (array $part): bool => ($part['type'] ?? '') === 'tool-invocation'));
    }

    /**
     * @return array{id: string, chatId: string, role: string, content: string, parts: null|list<array<string, mixed>>, createdAt: int}
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'chatId' => $this->chatId,
            'role' => $this->role,
            'content' => $this->content,
            'parts' => $this->parts,
            'createdAt' => $this->createdAt->getTimestamp(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $parts = $data['parts'] ?? null;
        if (\is_string($parts)) {
            $parts = json_decode($parts, true);
        }

        return new self(
            id: $data['id'],
            chatId: $data['chat_id'],
            role: $data['role'],
            content: $data['content'],
            parts: $parts,
            createdAt: (new \DateTimeImmutable())->setTimestamp((int) $data['created_at']),
        );
    }
}
