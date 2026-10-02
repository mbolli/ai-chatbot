<?php

declare(strict_types=1);

namespace App\Domain\Service\Stream;

/**
 * Always the last event of a stream.
 */
final readonly class StreamEnd {
    public function __construct(
        public StopReason $stopReason,
        public Usage $usage = new Usage(),
    ) {}
}
