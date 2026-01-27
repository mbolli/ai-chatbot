<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;

/**
 * SQLite implementation of UserRepositoryInterface.
 */
final class SqliteUserRepository implements UserRepositoryInterface {
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function findById(int $id): ?User {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? User::fromArray($row) : null;
    }

    public function findByEmail(string $email): ?User {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? User::fromArray($row) : null;
    }

    public function authenticate(string $email, string $password): ?User {
        $user = $this->findByEmail($email);

        if (!$user) {
            return null;
        }

        // Guest users cannot authenticate with password
        if ($user->isGuest) {
            return null;
        }

        // Verify password
        if (!password_verify($password, $user->passwordHash)) {
            return null;
        }

        return $user;
    }

    public function createUser(string $email, string $password): User {
        // Check if email already exists
        if ($this->findByEmail($email)) {
            throw new \RuntimeException('Email already exists');
        }

        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
        $createdAt = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, is_guest, created_at) VALUES (?, ?, 0, ?)'
        );
        $stmt->execute([$email, $passwordHash, $createdAt]);

        $id = (int) $this->pdo->lastInsertId();

        return new User(
            id: $id,
            email: $email,
            passwordHash: $passwordHash,
            roles: ['user'],
            details: [],
            isGuest: false,
            createdAt: $createdAt,
        );
    }

    public function createGuestUser(): User {
        $guestEmail = 'guest_' . bin2hex(random_bytes(8)) . '@guest.local';
        $createdAt = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, is_guest, created_at) VALUES (?, ?, 1, ?)'
        );
        $stmt->execute([$guestEmail, '', $createdAt]);

        $id = (int) $this->pdo->lastInsertId();

        return new User(
            id: $id,
            email: $guestEmail,
            passwordHash: '',
            roles: ['guest'],
            details: [],
            isGuest: true,
            createdAt: $createdAt,
        );
    }

    public function upgradeGuestUser(int $guestId, string $email, string $password): User {
        $guest = $this->findById($guestId);

        if (!$guest || !$guest->isGuest) {
            throw new \RuntimeException('Guest user not found');
        }

        // Check if email already exists
        if ($this->findByEmail($email)) {
            throw new \RuntimeException('Email already exists');
        }

        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

        $stmt = $this->pdo->prepare(
            'UPDATE users SET email = ?, password_hash = ?, is_guest = 0 WHERE id = ?'
        );
        $stmt->execute([$email, $passwordHash, $guestId]);

        return new User(
            id: $guestId,
            email: $email,
            passwordHash: $passwordHash,
            roles: ['user'],
            details: [],
            isGuest: false,
            createdAt: $guest->createdAt,
        );
    }

    public function updatePassword(int $userId, string $newPassword): void {
        $passwordHash = password_hash($newPassword, PASSWORD_ARGON2ID);

        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $userId]);
    }

    public function delete(int $userId): void {
        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }
}
