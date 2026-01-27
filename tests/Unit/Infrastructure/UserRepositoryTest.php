<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\Model\User;
use App\Infrastructure\Repository\SqliteUserRepository;

function getUserSchema(): string {
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS "users" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "email" TEXT NOT NULL COLLATE NOCASE,
    "password_hash" TEXT NOT NULL DEFAULT '' COLLATE BINARY,
    "is_guest" INTEGER NOT NULL DEFAULT 0,
    "created_at" TEXT NOT NULL DEFAULT (datetime('now')),
    CONSTRAINT "users_email_uq" UNIQUE ("email")
);
SQL;
}

beforeEach(function (): void {
    $this->pdo = new \PDO('sqlite::memory:');
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $this->pdo->exec(getUserSchema());
    $this->repository = new SqliteUserRepository($this->pdo);
});

describe('SqliteUserRepository', function (): void {
    describe('createUser', function (): void {
        it('creates a new user', function (): void {
            $user = $this->repository->createUser('test@example.com', 'password123');

            expect($user)->toBeInstanceOf(User::class);
            expect($user->email)->toBe('test@example.com');
            expect($user->isGuest)->toBeFalse();
            expect($user->id)->toBeGreaterThan(0);
        });

        it('hashes the password with Argon2id', function (): void {
            $user = $this->repository->createUser('test@example.com', 'password123');

            // Verify password was hashed with Argon2id
            expect(password_verify('password123', $user->passwordHash))->toBeTrue();
            expect($user->passwordHash)->toContain('$argon2id$');
        });

        it('throws exception for duplicate email', function (): void {
            $this->repository->createUser('test@example.com', 'password123');

            expect(fn () => $this->repository->createUser('test@example.com', 'different'))
                ->toThrow(\RuntimeException::class, 'Email already exists')
            ;
        });
    });

    describe('createGuestUser', function (): void {
        it('creates a guest user', function (): void {
            $user = $this->repository->createGuestUser();

            expect($user)->toBeInstanceOf(User::class);
            expect($user->isGuest)->toBeTrue();
            expect($user->email)->toContain('guest_');
            expect($user->email)->toContain('@guest.local');
        });

        it('creates unique guest emails', function (): void {
            $guest1 = $this->repository->createGuestUser();
            $guest2 = $this->repository->createGuestUser();

            expect($guest1->email)->not->toBe($guest2->email);
            expect($guest1->id)->not->toBe($guest2->id);
        });
    });

    describe('authenticate', function (): void {
        beforeEach(function (): void {
            $this->repository->createUser('test@example.com', 'password123');
        });

        it('authenticates with correct credentials', function (): void {
            $user = $this->repository->authenticate('test@example.com', 'password123');

            expect($user)->toBeInstanceOf(User::class);
            expect($user->email)->toBe('test@example.com');
        });

        it('returns null for wrong password', function (): void {
            $user = $this->repository->authenticate('test@example.com', 'wrongpassword');

            expect($user)->toBeNull();
        });

        it('returns null for non-existent email', function (): void {
            $user = $this->repository->authenticate('nonexistent@example.com', 'password123');

            expect($user)->toBeNull();
        });

        it('returns null for guest users', function (): void {
            $guest = $this->repository->createGuestUser();

            $user = $this->repository->authenticate($guest->email, '');

            expect($user)->toBeNull();
        });
    });

    describe('findById', function (): void {
        it('finds existing user', function (): void {
            $created = $this->repository->createUser('test@example.com', 'password123');
            $found = $this->repository->findById($created->id);

            expect($found)->not->toBeNull();
            expect($found->email)->toBe('test@example.com');
        });

        it('returns null for non-existent id', function (): void {
            $found = $this->repository->findById(999);

            expect($found)->toBeNull();
        });
    });

    describe('findByEmail', function (): void {
        it('finds existing user', function (): void {
            $this->repository->createUser('test@example.com', 'password123');
            $found = $this->repository->findByEmail('test@example.com');

            expect($found)->not->toBeNull();
            expect($found->email)->toBe('test@example.com');
        });

        it('returns null for non-existent email', function (): void {
            $found = $this->repository->findByEmail('nonexistent@example.com');

            expect($found)->toBeNull();
        });
    });

    describe('upgradeGuestUser', function (): void {
        it('upgrades guest to registered user', function (): void {
            $guest = $this->repository->createGuestUser();
            $upgraded = $this->repository->upgradeGuestUser(
                $guest->id,
                'upgraded@example.com',
                'newpassword123'
            );

            expect($upgraded->id)->toBe($guest->id);
            expect($upgraded->email)->toBe('upgraded@example.com');
            expect($upgraded->isGuest)->toBeFalse();
            expect(password_verify('newpassword123', $upgraded->passwordHash))->toBeTrue();
        });

        it('throws exception if user not found', function (): void {
            expect(fn () => $this->repository->upgradeGuestUser(999, 'test@example.com', 'password'))
                ->toThrow(\RuntimeException::class, 'Guest user not found')
            ;
        });

        it('throws exception if not a guest', function (): void {
            $user = $this->repository->createUser('test@example.com', 'password123');

            expect(fn () => $this->repository->upgradeGuestUser($user->id, 'new@example.com', 'password'))
                ->toThrow(\RuntimeException::class, 'Guest user not found')
            ;
        });

        it('throws exception if email already taken', function (): void {
            $this->repository->createUser('taken@example.com', 'password123');
            $guest = $this->repository->createGuestUser();

            expect(fn () => $this->repository->upgradeGuestUser($guest->id, 'taken@example.com', 'password'))
                ->toThrow(\RuntimeException::class, 'Email already exists')
            ;
        });
    });

    describe('updatePassword', function (): void {
        it('updates password', function (): void {
            $user = $this->repository->createUser('test@example.com', 'oldpassword');
            $this->repository->updatePassword($user->id, 'newpassword');

            $updated = $this->repository->authenticate('test@example.com', 'newpassword');

            expect($updated)->not->toBeNull();
        });
    });

    describe('delete', function (): void {
        it('deletes user', function (): void {
            $user = $this->repository->createUser('test@example.com', 'password123');
            $this->repository->delete($user->id);

            $found = $this->repository->findById($user->id);

            expect($found)->toBeNull();
        });
    });
});
