<?php

declare(strict_types=1);

namespace App\Infrastructure\Session;

use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionPersistenceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Table;

/**
 * Swoole Table-based session persistence.
 *
 * Uses Swoole's shared memory table for coroutine-safe session storage.
 * Sessions are stored in memory and survive across requests within the
 * same Swoole server process.
 */
final class SwooleTableSessionPersistence implements SessionPersistenceInterface {
    private const string COOKIE_NAME = 'PHPSESSID';
    private const int SESSION_TTL = 3600; // 1 hour

    private readonly Table $table;

    /**
     * Get the shared table instance (singleton for the Swoole process).
     */
    private static ?Table $sharedTable = null;

    public function __construct(
        private readonly string $cookieName = self::COOKIE_NAME,
        private readonly int $sessionTtl = self::SESSION_TTL,
        ?Table $table = null,
    ) {
        // Use provided table or create new one
        if ($table !== null) {
            $this->table = $table;
        } else {
            $this->table = self::createDefaultTable();
        }
    }

    /**
     * Create a default Swoole Table for session storage.
     */
    public static function createDefaultTable(int $size = 10240): Table {
        $table = new Table($size);
        // session_data stores serialized session as JSON (max 8KB)
        $table->column('session_data', Table::TYPE_STRING, 8192);
        // expires_at stores Unix timestamp
        $table->column('expires_at', Table::TYPE_INT);
        $table->create();

        return $table;
    }

    public static function getSharedTable(): Table {
        if (self::$sharedTable === null) {
            self::$sharedTable = self::createDefaultTable();
        }

        return self::$sharedTable;
    }

    public static function resetSharedTable(): void {
        self::$sharedTable = null;
    }

    /**
     * Initialize a session from the request.
     */
    public function initializeSessionFromRequest(ServerRequestInterface $request): Session {
        $sessionId = $this->getSessionIdFromRequest($request);
        $sessionData = [];

        if ($sessionId !== '') {
            $sessionData = $this->loadSession($sessionId);
        }

        // If no session exists, we'll create one when persisting
        return new Session($sessionData, $sessionId ?: $this->generateSessionId());
    }

    /**
     * Persist session data and return modified response with session cookie.
     */
    public function persistSession(SessionInterface $session, ResponseInterface $response): ResponseInterface {
        // initializeSessionFromRequest always returns Session which has getId()
        /** @var Session $session */
        $sessionId = $session->getId();

        if ($session->isRegenerated()) {
            // Delete old session and generate new ID
            $this->deleteSession($sessionId);
            $sessionId = $this->generateSessionId();
        }

        // Store session data
        $this->saveSession($sessionId, $session->toArray());

        // Set cookie on response
        $cookie = $this->buildCookie($sessionId);

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    /**
     * Clean up expired sessions (call periodically).
     */
    public function garbageCollect(): int {
        $now = time();
        $deleted = 0;

        foreach ($this->table as $sessionId => $row) {
            if ($row['expires_at'] < $now) {
                $this->table->del($sessionId);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Get session data by session ID (for SSE listener).
     *
     * @return array<string, mixed>
     */
    public function getSessionData(string $sessionId): array {
        return $this->loadSession($sessionId);
    }

    /**
     * Get session ID from request cookies.
     */
    private function getSessionIdFromRequest(ServerRequestInterface $request): string {
        $cookies = $request->getCookieParams();

        return $cookies[$this->cookieName] ?? '';
    }

    /**
     * Load session data from Swoole Table.
     *
     * @return array<string, mixed>
     */
    private function loadSession(string $sessionId): array {
        $row = $this->table->get($sessionId);

        if ($row === false) {
            return [];
        }

        // Check if session has expired
        if ($row['expires_at'] < time()) {
            $this->deleteSession($sessionId);

            return [];
        }

        $data = json_decode($row['session_data'], true);

        return \is_array($data) ? $data : [];
    }

    /**
     * Save session data to Swoole Table.
     *
     * @param array<string, mixed> $data
     */
    private function saveSession(string $sessionId, array $data): void {
        $this->table->set($sessionId, [
            'session_data' => json_encode($data),
            'expires_at' => time() + $this->sessionTtl,
        ]);
    }

    /**
     * Delete a session from the table.
     */
    private function deleteSession(string $sessionId): void {
        $this->table->del($sessionId);
    }

    /**
     * Generate a cryptographically secure session ID.
     */
    private function generateSessionId(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Build the Set-Cookie header value.
     */
    private function buildCookie(string $sessionId): string {
        $expires = gmdate('D, d M Y H:i:s T', time() + $this->sessionTtl);

        return \sprintf(
            '%s=%s; Path=/; Expires=%s; HttpOnly; SameSite=Lax',
            $this->cookieName,
            $sessionId,
            $expires
        );
    }
}
