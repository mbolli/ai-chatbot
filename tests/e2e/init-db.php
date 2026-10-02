<?php

declare(strict_types=1);

// Creates a fresh SQLite database for the e2e server from data/schema.sql.
$dir = getenv('E2E_DATA_DIR');
if ($dir === false || $dir === '') {
    fwrite(STDERR, "E2E_DATA_DIR is not set\n");

    exit(1);
}

if (!is_dir($dir)) {
    mkdir($dir, 0o755, true);
}

foreach (['db.sqlite', 'db.sqlite-wal', 'db.sqlite-shm', 'swoole.pid'] as $file) {
    if (is_file("{$dir}/{$file}")) {
        unlink("{$dir}/{$file}");
    }
}

$pdo = new PDO("sqlite:{$dir}/db.sqlite");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string) file_get_contents(__DIR__ . '/../../data/schema.sql'));
