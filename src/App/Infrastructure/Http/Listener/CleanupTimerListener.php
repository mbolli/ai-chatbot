<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Listener;

use App\Infrastructure\AI\StreamingSessionManager;
use App\Infrastructure\Session\SwooleTableSessionPersistence;
use Mezzio\Swoole\Event\WorkerStartEvent;
use OpenSwoole\Timer;

/**
 * Worker start listener that sets up periodic cleanup tasks.
 *
 * Runs cleanup timers for:
 * - Stale streaming sessions (every 30 seconds)
 * - Expired user sessions (every 5 minutes)
 */
final class CleanupTimerListener {
    private const int STREAMING_CLEANUP_INTERVAL_MS = 30_000; // 30 seconds
    private const int SESSION_CLEANUP_INTERVAL_MS = 300_000;  // 5 minutes

    public function __construct(
        private readonly StreamingSessionManager $streamingSessionManager,
        private readonly SwooleTableSessionPersistence $sessionPersistence,
    ) {}

    public function __invoke(WorkerStartEvent $event): void {
        // Only run cleanup timers on worker 0 to avoid duplicate work
        if ($event->getWorkerId() !== 0) {
            return;
        }

        // Set up periodic cleanup for stale streaming sessions
        Timer::tick(self::STREAMING_CLEANUP_INTERVAL_MS, function (): void {
            $cleaned = $this->streamingSessionManager->cleanupStaleSessions();
            if ($cleaned > 0) {
                error_log("[Cleanup] Removed {$cleaned} stale streaming sessions");
            }
        });

        // Set up periodic cleanup for expired user sessions
        Timer::tick(self::SESSION_CLEANUP_INTERVAL_MS, function (): void {
            $cleaned = $this->sessionPersistence->garbageCollect();
            if ($cleaned > 0) {
                error_log("[Cleanup] Removed {$cleaned} expired user sessions");
            }
        });

        error_log('[Worker 0] Cleanup timers started');
    }
}
