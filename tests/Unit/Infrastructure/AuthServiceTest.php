<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\Model\User;
use App\Infrastructure\Auth\AuthService;
use App\Infrastructure\Repository\SqliteUserRepository;
use Mezzio\Session\Session;

/**
 * Get the simplified auth schema.
 */
function getAuthSchema(): string {
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
    // Create in-memory database
    $this->pdo = new \PDO('sqlite::memory:');
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $this->pdo->exec(getAuthSchema());

    // Create repository and service
    $this->userRepository = new SqliteUserRepository($this->pdo);
    $this->authService = new AuthService($this->userRepository);

    // Create a fresh session for each test
    $this->session = new Session([]);
});

describe('AuthService', function (): void {
    describe('register', function (): void {
        it('registers a new user successfully', function (): void {
            $result = $this->authService->register(
                $this->session,
                'test@example.com',
                'password123'
            );

            expect($result['success'])->toBeTrue();
            expect($result['user'])->toBeInstanceOf(User::class);
            expect($result['user']->email)->toBe('test@example.com');
            expect($result['user']->isGuest)->toBeFalse();
        });

        it('auto-logs in after registration', function (): void {
            $this->authService->register(
                $this->session,
                'test@example.com',
                'password123'
            );

            expect($this->authService->isLoggedIn($this->session))->toBeTrue();
        });

        it('fails with invalid email', function (): void {
            $result = $this->authService->register(
                $this->session,
                'invalid-email',
                'password123'
            );

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Invalid email address');
        });

        it('fails with short password', function (): void {
            $result = $this->authService->register(
                $this->session,
                'test@example.com',
                'short'
            );

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Password must be at least 8 characters');
        });

        it('fails with duplicate email', function (): void {
            $this->authService->register(
                $this->session,
                'test@example.com',
                'password123'
            );

            $result = $this->authService->register(
                new Session([]),
                'test@example.com',
                'different123'
            );

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Email already exists');
        });
    });

    describe('login', function (): void {
        beforeEach(function (): void {
            // Create a user first
            $this->userRepository->createUser('user@example.com', 'password123');
        });

        it('logs in with correct credentials', function (): void {
            $result = $this->authService->login(
                $this->session,
                'user@example.com',
                'password123'
            );

            expect($result['success'])->toBeTrue();
            expect($result['user'])->toBeInstanceOf(User::class);
            expect($this->authService->isLoggedIn($this->session))->toBeTrue();
        });

        it('fails with wrong password', function (): void {
            $result = $this->authService->login(
                $this->session,
                'user@example.com',
                'wrongpassword'
            );

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Invalid email or password');
        });

        it('fails with non-existent email', function (): void {
            $result = $this->authService->login(
                $this->session,
                'nonexistent@example.com',
                'password123'
            );

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Invalid email or password');
        });
    });

    describe('logout', function (): void {
        it('clears session on logout', function (): void {
            $this->authService->register(
                $this->session,
                'test@example.com',
                'password123'
            );

            expect($this->authService->isLoggedIn($this->session))->toBeTrue();

            $this->authService->logout($this->session);

            expect($this->authService->isLoggedIn($this->session))->toBeFalse();
        });
    });

    describe('guest users', function (): void {
        it('creates a guest user', function (): void {
            $result = $this->authService->createGuestUser($this->session);

            expect($result['success'])->toBeTrue();
            expect($result['user'])->toBeInstanceOf(User::class);
            expect($result['user']->isGuest)->toBeTrue();
            expect($this->authService->isLoggedIn($this->session))->toBeTrue();
        });

        it('identifies guest status correctly', function (): void {
            $this->authService->createGuestUser($this->session);

            expect($this->authService->isGuest($this->session))->toBeTrue();
        });

        it('registered users are not guests', function (): void {
            $this->authService->register(
                $this->session,
                'test@example.com',
                'password123'
            );

            expect($this->authService->isGuest($this->session))->toBeFalse();
        });
    });

    describe('getOrCreateUser', function (): void {
        it('returns existing user without creating new one', function (): void {
            $this->authService->register(
                $this->session,
                'test@example.com',
                'password123'
            );

            $result = $this->authService->getOrCreateUser($this->session);

            expect($result['user']->email)->toBe('test@example.com');
            expect($result['created'])->toBeFalse();
        });

        it('creates guest user if not logged in', function (): void {
            $result = $this->authService->getOrCreateUser($this->session);

            expect($result['user']->isGuest)->toBeTrue();
            expect($result['created'])->toBeTrue();
        });
    });

    describe('upgradeGuestAccount', function (): void {
        beforeEach(function (): void {
            // Create a guest user
            $this->authService->createGuestUser($this->session);
        });

        it('upgrades guest to registered user', function (): void {
            $result = $this->authService->upgradeGuestAccount(
                $this->session,
                'upgraded@example.com',
                'newpassword123'
            );

            expect($result['success'])->toBeTrue();
            expect($result['user']->email)->toBe('upgraded@example.com');
            expect($result['user']->isGuest)->toBeFalse();
        });

        it('preserves user id after upgrade', function (): void {
            $guestId = $this->authService->getUser($this->session)->id;

            $result = $this->authService->upgradeGuestAccount(
                $this->session,
                'upgraded@example.com',
                'newpassword123'
            );

            expect($result['user']->id)->toBe($guestId);
        });

        it('fails for non-guest accounts', function (): void {
            $this->authService->logout($this->session);
            $this->authService->register(
                $this->session,
                'regular@example.com',
                'password123'
            );

            $result = $this->authService->upgradeGuestAccount(
                $this->session,
                'newemail@example.com',
                'newpassword123'
            );

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Not a guest account');
        });

        it('fails with invalid email', function (): void {
            $result = $this->authService->upgradeGuestAccount(
                $this->session,
                'invalid-email',
                'newpassword123'
            );

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Invalid email address');
        });

        it('fails with short password', function (): void {
            $result = $this->authService->upgradeGuestAccount(
                $this->session,
                'upgraded@example.com',
                'short'
            );

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Password must be at least 8 characters');
        });

        it('fails if email already taken', function (): void {
            // Create another user with the target email
            $this->pdo->exec("INSERT INTO users (email, password_hash, is_guest, created_at) VALUES ('taken@example.com', 'hash', 0, datetime('now'))");

            $result = $this->authService->upgradeGuestAccount(
                $this->session,
                'taken@example.com',
                'newpassword123'
            );

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Email already exists');
        });
    });

    describe('getUser', function (): void {
        it('returns null when not logged in', function (): void {
            expect($this->authService->getUser($this->session))->toBeNull();
        });

        it('returns user when logged in', function (): void {
            $this->authService->register(
                $this->session,
                'test@example.com',
                'password123'
            );

            $user = $this->authService->getUser($this->session);

            expect($user)->toBeInstanceOf(User::class);
            expect($user->email)->toBe('test@example.com');
        });
    });
});
