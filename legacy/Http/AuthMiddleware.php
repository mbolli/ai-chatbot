<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Model\User;
use App\Infrastructure\Session\SwooleTableSessionPersistence;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Session and authentication middleware for Swoole.
 *
 * This middleware:
 * 1. Initializes request-scoped sessions (via SwooleTableSessionPersistence)
 * 2. Ensures a user context exists (creates guest if needed)
 * 3. Adds session and user to request attributes
 * 4. Persists session changes in response
 */
final class AuthMiddleware implements MiddlewareInterface {
    public const string ATTR_SESSION = 'session';
    public const string ATTR_USER = 'user';
    public const string ATTR_USER_ID = 'userId';
    public const string ATTR_AUTH = 'auth';

    public function __construct(
        private readonly AuthService $authService,
        private readonly SwooleTableSessionPersistence $sessionPersistence,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        // Initialize session from request cookies
        $session = $this->sessionPersistence->initializeSessionFromRequest($request);

        // Ensure a user exists (create guest if needed)
        $result = $this->authService->getOrCreateUser($session);
        $user = $result['user'];

        // Add session and user info to request attributes
        $request = $request
            ->withAttribute(self::ATTR_SESSION, $session)
            ->withAttribute(self::ATTR_USER, $user)
            ->withAttribute(self::ATTR_USER_ID, $user->id)
            ->withAttribute(self::ATTR_AUTH, $this->authService)
        ;

        // Handle request
        $response = $handler->handle($request);

        // Persist session to response (sets cookies)
        return $this->sessionPersistence->persistSession($session, $response);
    }

    /**
     * Helper to get session from request attributes.
     */
    public static function getSession(ServerRequestInterface $request): SessionInterface {
        $session = $request->getAttribute(self::ATTR_SESSION);

        if (!$session instanceof SessionInterface) {
            throw new \RuntimeException('Session not found in request. Is AuthMiddleware registered?');
        }

        return $session;
    }

    /**
     * Helper to get current user from request attributes.
     */
    public static function getUser(ServerRequestInterface $request): User {
        $user = $request->getAttribute(self::ATTR_USER);

        if (!$user instanceof User) {
            throw new \RuntimeException('User not found in request. Is AuthMiddleware registered?');
        }

        return $user;
    }

    /**
     * Helper to get user ID from request attributes.
     */
    public static function getUserId(ServerRequestInterface $request): int {
        return self::getUser($request)->id;
    }

    /**
     * Helper to get AuthService from request attributes.
     */
    public static function getAuthService(ServerRequestInterface $request): AuthService {
        $auth = $request->getAttribute(self::ATTR_AUTH);

        if (!$auth instanceof AuthService) {
            throw new \RuntimeException('AuthService not found in request. Is AuthMiddleware registered?');
        }

        return $auth;
    }
}
