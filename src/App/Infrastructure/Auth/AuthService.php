<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;
use Mezzio\Session\SessionInterface;

/**
 * Authentication service using Mezzio session-based authentication.
 *
 * This service manages user authentication through request-scoped sessions,
 * making it fully compatible with Swoole coroutines (no global state).
 */
final class AuthService {
    private const string USER_SESSION_KEY = 'authenticated_user';

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * Get the current user from session.
     */
    public function getUser(SessionInterface $session): ?User {
        $userId = $session->get(self::USER_SESSION_KEY);

        if ($userId === null) {
            return null;
        }

        return $this->userRepository->findById((int) $userId);
    }

    /**
     * Check if a user is currently logged in.
     */
    public function isLoggedIn(SessionInterface $session): bool {
        return $session->has(self::USER_SESSION_KEY);
    }

    /**
     * Check if the current user is a guest.
     */
    public function isGuest(SessionInterface $session): bool {
        $user = $this->getUser($session);

        return $user !== null && $user->isGuest;
    }

    /**
     * Register a new user.
     *
     * @return array{success: bool, user?: User, error?: string}
     */
    public function register(
        SessionInterface $session,
        string $email,
        string $password,
    ): array {
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address'];
        }

        // Validate password length
        if (mb_strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters'];
        }

        try {
            $user = $this->userRepository->createUser($email, $password);

            // Auto-login after registration
            $session->set(self::USER_SESSION_KEY, $user->id);

            return ['success' => true, 'user' => $user];
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Log in a user.
     *
     * @return array{success: bool, user?: User, error?: string}
     */
    public function login(
        SessionInterface $session,
        string $email,
        string $password,
    ): array {
        $user = $this->userRepository->authenticate($email, $password);

        if ($user === null) {
            return ['success' => false, 'error' => 'Invalid email or password'];
        }

        // Regenerate session for security
        $session->regenerate();
        $session->set(self::USER_SESSION_KEY, $user->id);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Log out the current user.
     */
    public function logout(SessionInterface $session): void {
        $session->unset(self::USER_SESSION_KEY);
        $session->regenerate();
    }

    /**
     * Create a guest user for anonymous access.
     *
     * @return array{success: bool, user?: User, error?: string}
     */
    public function createGuestUser(SessionInterface $session): array {
        try {
            $user = $this->userRepository->createGuestUser();

            // Auto-login the guest
            $session->set(self::USER_SESSION_KEY, $user->id);

            return ['success' => true, 'user' => $user];
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get or create a user (guest if not logged in).
     * This ensures there's always a user context for operations.
     *
     * @return array{user: User, created: bool}
     */
    public function getOrCreateUser(SessionInterface $session): array {
        $user = $this->getUser($session);

        if ($user !== null) {
            return ['user' => $user, 'created' => false];
        }

        $result = $this->createGuestUser($session);

        if (!$result['success'] || !isset($result['user'])) {
            throw new \RuntimeException('Failed to create guest user');
        }

        return ['user' => $result['user'], 'created' => true];
    }

    /**
     * Upgrade a guest account to a full account.
     *
     * @return array{success: bool, user?: User, error?: string}
     */
    public function upgradeGuestAccount(
        SessionInterface $session,
        string $email,
        string $password,
    ): array {
        if (!$this->isGuest($session)) {
            return ['success' => false, 'error' => 'Not a guest account'];
        }

        $currentUser = $this->getUser($session);
        if ($currentUser === null) {
            return ['success' => false, 'error' => 'Not logged in'];
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address'];
        }

        // Validate password length
        if (mb_strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters'];
        }

        try {
            $user = $this->userRepository->upgradeGuestUser(
                $currentUser->id,
                $email,
                $password
            );

            // Update session with upgraded user
            $session->regenerate();
            $session->set(self::USER_SESSION_KEY, $user->id);

            return ['success' => true, 'user' => $user];
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
