#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Initialize the SQLite database with schema.
 */
$dbPath = __DIR__ . '/../data/db.sqlite';
$schemaPath = __DIR__ . '/../data/schema.sql';

// Create data directory if not exists
if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0755, true);
}

// Check if database already exists
$exists = file_exists($dbPath);

if ($exists && !in_array('--force', $argv, true)) {
    echo "Database already exists at: {$dbPath}\n";
    echo "Use --force to recreate.\n";

    exit(0);
}

// Remove existing database if force flag is set
if ($exists && in_array('--force', $argv, true)) {
    unlink($dbPath);
    echo "Removed existing database.\n";
}

// Create new database
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Read and execute schema
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        throw new RuntimeException("Could not read schema file: {$schemaPath}");
    }

    $pdo->exec($schema);

    echo "Database initialized successfully at: {$dbPath}\n";
} catch (Throwable $e) {
    echo 'Error initializing database: ' . $e->getMessage() . "\n";

    exit(1);
}
