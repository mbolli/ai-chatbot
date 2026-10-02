<?php

declare(strict_types=1);

namespace App\Domain\Service\Stream;

/**
 * Emitted right before a tool is executed.
 */
final readonly class ToolCall {
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
    ) {}
}
