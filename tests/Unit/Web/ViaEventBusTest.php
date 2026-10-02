<?php

declare(strict_types=1);

namespace Tests\Unit\Web;

use App\Domain\Event\RateLimitExceededEvent;
use App\Domain\Service\RateLimitType;
use App\Web\LiveState;
use App\Web\ViaEventBus;

it('ends the generating state with a rate-limit toast and sends guests to the upgrade dialog', function (bool $isGuest, array $signals): void {
    $state = new LiveState();
    new ViaEventBus($state)->emit(7, new RateLimitExceededEvent(7, 'chat', 10, 10, $isGuest, RateLimitType::HourlyRequests));

    expect($state->toasts(7))->toHaveCount(1)
        ->and($state->toasts(7)[0]['signals'])->toBe($signals)
    ;
})->with([
    'guest' => [true, ['_generatingMessage' => '', '_authModal' => 'upgrade']],
    'registered' => [false, ['_generatingMessage' => '']],
]);
