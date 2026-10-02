#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\SchemaMigrator;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Upgrade data/db.sqlite to the current schema. Safe to run repeatedly.
 */
$dbPath = $argv[1] ?? __DIR__ . '/../data/db.sqlite';

if (!file_exists($dbPath)) {
    echo "No database at {$dbPath}. Run composer db:init first.\n";

    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $migrator = new SchemaMigrator($pdo);
    $from = $migrator->currentVersion();
    $applied = $migrator->migrate();

    echo $applied === []
        ? "Database is up to date (version {$from}).\n"
        : 'Migrated from version ' . $from . ' to ' . $migrator->currentVersion() . ".\n";
} catch (Throwable $e) {
    echo 'Migration failed: ' . $e->getMessage() . "\n";

    exit(1);
}
