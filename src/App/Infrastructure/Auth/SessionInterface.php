<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

interface SessionInterface {
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function set(string $key, mixed $value): void;

    public function unset(string $key): void;

    /**
     * Called on login and logout to prevent session fixation.
     */
    public function regenerate(): void;
}
