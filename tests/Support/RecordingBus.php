<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\EventBus\EventBusInterface;

final class RecordingBus implements EventBusInterface {
    /** @var list<array{int, object}> user id and event, in emit order */
    public array $emitted = [];

    public function emit(int $userId, object $event): void {
        $this->emitted[] = [$userId, $event];
    }

    /**
     * @return list<object>
     */
    public function events(): array {
        return array_column($this->emitted, 1);
    }
}
