<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Auth\SessionInterface;

final class ArraySession implements SessionInterface {
    public int $regenerations = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = []) {}

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool {
        return isset($this->data[$key]);
    }

    public function set(string $key, mixed $value): void {
        $this->data[$key] = $value;
    }

    public function unset(string $key): void {
        unset($this->data[$key]);
    }

    public function regenerate(): void {
        ++$this->regenerations;
    }
}
