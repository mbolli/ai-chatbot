<?php

declare(strict_types=1);

namespace App\Domain\Service\Stream;

/**
 * Token usage summed over every request of one streamed response (tool continuations included).
 */
final readonly class Usage {
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    public function add(self $other): self {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
        );
    }
}
