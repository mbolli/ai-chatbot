<?php

declare(strict_types=1);

namespace App\Domain\Service\Stream;

/**
 * Emitted after a tool ran, with the text that was sent back to the model.
 */
final readonly class ToolResult {
    public function __construct(
        public string $id,
        public string $name,
        public string $content,
        public bool $isError = false,
    ) {}
}
