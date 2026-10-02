<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Swoole\Coroutine;

/**
 * Retries a provider request on transient failures, but only while it has not yielded anything to the caller.
 */
final readonly class RetryPolicy {
    private const array RETRYABLE_STATUSES = [408, 409, 429, 500, 502, 503, 504, 529];

    /**
     * @param int                         $maxAttempts Attempts per request, the first one included
     * @param float                       $maxDelay    A longer retry-after fails immediately instead of blocking the user
     * @param null|\Closure(float): mixed $sleep       Defaults to Swoole\Coroutine::sleep()
     */
    public function __construct(
        private int $maxAttempts = 3,
        private float $baseDelay = 0.5,
        private float $maxDelay = 20.0,
        private ?\Closure $sleep = null,
    ) {}

    public function shouldRetry(TransportException $e, int $attempt): bool {
        return $attempt < $this->maxAttempts
            && ($e->status === null || \in_array($e->status, self::RETRYABLE_STATUSES, true))
            && ($e->retryAfter === null || $e->retryAfter <= $this->maxDelay);
    }

    /**
     * Seconds to wait before retrying after the given failed attempt (1-based).
     */
    public function delay(int $attempt, ?float $retryAfter = null): float {
        if ($retryAfter !== null) {
            return $retryAfter;
        }

        $jitter = 0.75 + mt_rand() / getrandmax() / 2;

        return min($this->maxDelay, $this->baseDelay * 2 ** ($attempt - 1) * $jitter);
    }

    /**
     * Parses a retry-after header given in seconds. HTTP dates are ignored.
     */
    public static function parseRetryAfter(?string $value): ?float {
        $value = trim($value ?? '');

        return is_numeric($value) ? max(0.0, (float) $value) : null;
    }

    /**
     * @template TYield
     * @template TReturn
     *
     * @param callable(string): \Generator<int, TYield, mixed, TReturn> $attempt Sends the request body once
     *
     * @return \Generator<int, TYield, mixed, TReturn>
     *
     * @throws TransportException When retries are exhausted, not allowed, or the attempt already yielded
     */
    public function run(callable $attempt, string $body): \Generator {
        for ($n = 1;; ++$n) {
            $yielded = false;

            try {
                $generator = $attempt($body);
                foreach ($generator as $event) {
                    $yielded = true;

                    yield $event;
                }

                return $generator->getReturn();
            } catch (TransportException $e) {
                if ($yielded || !$this->shouldRetry($e, $n)) {
                    throw $e;
                }

                $delay = $this->delay($n, $e->retryAfter);
                error_log(\sprintf('AI request failed (%s), retrying in %.2fs', $e->getMessage(), $delay));
                ($this->sleep ?? Coroutine::sleep(...))($delay);
            }
        }
    }
}
