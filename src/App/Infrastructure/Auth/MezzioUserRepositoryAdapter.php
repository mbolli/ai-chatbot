<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;
use Mezzio\Authentication\UserInterface;
use Mezzio\Authentication\UserRepositoryInterface as MezzioUserRepositoryInterface;

/**
 * Adapter that wraps our UserRepositoryInterface for Mezzio's authentication system.
 *
 * Mezzio's UserRepositoryInterface expects authenticate(credential, password)
 * where credential is typically a username/email string.
 */
final class MezzioUserRepositoryAdapter implements MezzioUserRepositoryInterface {
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * Authenticate a user by credential (email) and password.
     */
    public function authenticate(string $credential, ?string $password = null): ?UserInterface {
        if ($password === null) {
            return null;
        }

        return $this->userRepository->authenticate($credential, $password);
    }
}
