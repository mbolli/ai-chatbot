<?php

declare(strict_types=1);

namespace App\Web;

use App\Infrastructure\Auth\SessionInterface;
use Mbolli\PhpVia\Context;

/**
 * Auth session on top of php-via's per-session data.
 */
final class ViaSession implements SessionInterface {
    public function __construct(private readonly Context $context) {}

    public function get(string $key, mixed $default = null): mixed {
        return $this->context->sessionData($key, $default);
    }

    public function has(string $key): bool {
        return $this->context->sessionData($key) !== null;
    }

    public function set(string $key, mixed $value): void {
        $this->context->setSessionData($key, $value);
    }

    public function unset(string $key): void {
        $this->context->setSessionData($key, null);
    }

    public function regenerate(): void {
        // php-via has no session id rotation yet, so a fixated session id survives login
    }
}
