<?php

declare(strict_types=1);

namespace App\Domain\Service\Stream;

/**
 * Readable reasoning text (summarized thinking or progress updates), never the raw chain of thought.
 */
final readonly class ThinkingDelta {
    public function __construct(public string $text) {}
}
