<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

/**
 * A provider request that failed. The status is null for connection failures, which are always retryable.
 */
final class TransportException extends \RuntimeException {
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly string $body = '',
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status ?? 0);
    }
}
