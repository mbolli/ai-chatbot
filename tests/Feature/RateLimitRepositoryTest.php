<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Persistence\SqliteRateLimitRepository;

beforeEach(function (): void {
    $this->pdo = new \PDO('sqlite::memory:');
    $this->pdo->exec(file_get_contents(__DIR__ . '/../../data/schema.sql'));

    // Create a test user
    $this->pdo->exec("INSERT INTO users (id, email, is_guest) VALUES (1, 'test@example.com', 0)");

    $this->repo = new SqliteRateLimitRepository($this->pdo);
});

it('returns 0 for user with no messages', function (): void {
    $count = $this->repo->getMessageCount(1, date('Y-m-d'));

    expect($count)->toBe(0);
});

it('increments message count', function (): void {
    $today = date('Y-m-d');

    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(1, $today);

    expect($this->repo->getMessageCount(1, $today))->toBe(3);
});

it('tracks counts separately by date', function (): void {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(1, $yesterday);

    expect($this->repo->getMessageCount(1, $today))->toBe(2);
    expect($this->repo->getMessageCount(1, $yesterday))->toBe(1);
});

it('tracks counts separately by user', function (): void {
    // Create second user
    $this->pdo->exec("INSERT INTO users (id, email, is_guest) VALUES (2, 'other@example.com', 0)");

    $today = date('Y-m-d');

    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(2, $today);

    expect($this->repo->getMessageCount(1, $today))->toBe(2);
    expect($this->repo->getMessageCount(2, $today))->toBe(1);
});

it('returns true when under limit', function (): void {
    $today = date('Y-m-d');

    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(1, $today);

    expect($this->repo->isUnderLimit(1, $today, 5))->toBeTrue();
});

it('returns false when at limit', function (): void {
    $today = date('Y-m-d');

    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(1, $today);

    expect($this->repo->isUnderLimit(1, $today, 3))->toBeFalse();
});

it('returns false when over limit', function (): void {
    $today = date('Y-m-d');

    for ($i = 0; $i < 5; ++$i) {
        $this->repo->incrementMessageCount(1, $today);
    }

    expect($this->repo->isUnderLimit(1, $today, 3))->toBeFalse();
});

it('cleans up old records', function (): void {
    $today = date('Y-m-d');
    $oldDate = date('Y-m-d', strtotime('-10 days'));

    $this->repo->incrementMessageCount(1, $today);
    $this->repo->incrementMessageCount(1, $oldDate);

    $deleted = $this->repo->cleanupOldRecords(7);

    expect($deleted)->toBe(1);
    expect($this->repo->getMessageCount(1, $today))->toBe(1);
    expect($this->repo->getMessageCount(1, $oldDate))->toBe(0);
});
