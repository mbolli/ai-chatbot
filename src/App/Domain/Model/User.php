<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Mezzio\Authentication\UserInterface;

/**
 * User model implementing Mezzio's UserInterface.
 */
final class User implements UserInterface {
    /**
     * @param array<string>        $roles
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly array $roles = ['user'],
        public readonly array $details = [],
        public readonly bool $isGuest = false,
        public readonly ?string $createdAt = null,
    ) {}

    /**
     * Get the unique user identity.
     */
    public function getIdentity(): string {
        return (string) $this->id;
    }

    /**
     * Get all user roles.
     *
     * @return array<string>
     */
    public function getRoles(): array {
        return $this->roles;
    }

    /**
     * Get a detail by name.
     */
    public function getDetail(string $name, mixed $default = null): mixed {
        return $this->details[$name] ?? match ($name) {
            'id' => $this->id,
            'email' => $this->email,
            'isGuest' => $this->isGuest,
            default => $default,
        };
    }

    /**
     * Get all details.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array {
        return array_merge($this->details, [
            'id' => $this->id,
            'email' => $this->email,
            'isGuest' => $this->isGuest,
        ]);
    }

    /**
     * Check if this is a guest user.
     */
    public function isGuest(): bool {
        return $this->isGuest;
    }

    /**
     * Create from database row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self {
        return new self(
            id: (int) $row['id'],
            email: $row['email'],
            passwordHash: $row['password_hash'] ?? '',
            roles: isset($row['roles']) ? explode(',', $row['roles']) : ['user'],
            details: [],
            isGuest: (bool) ($row['is_guest'] ?? false),
            createdAt: $row['created_at'] ?? null,
        );
    }

    /**
     * Convert to array for persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'password_hash' => $this->passwordHash,
            'roles' => implode(',', $this->roles),
            'is_guest' => $this->isGuest ? 1 : 0,
            'created_at' => $this->createdAt,
        ];
    }
}
