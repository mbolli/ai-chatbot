<?php

declare(strict_types=1);

namespace App\Domain\Service\Stream;

final readonly class TextDelta {
    public function __construct(public string $text) {}
}
