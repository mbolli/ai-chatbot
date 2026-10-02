<?php

declare(strict_types=1);

// Overrides for the Playwright suite (playwright.config.ts sets E2E_DATA_DIR and E2E_PORT).
// Loaded after the local config files, so a developer's port override cannot win.
$dataDir = getenv('E2E_DATA_DIR');

if ($dataDir === false || $dataDir === '') {
    return [];
}

return [
    'database' => [
        'path' => $dataDir . '/db.sqlite',
    ],
    // Placeholder keys: every model is selectable, and a stray real AI call fails instead of costing money.
    'ai' => [
        'anthropic_api_key' => 'e2e-offline',
        'openai_api_key' => 'e2e-offline',
    ],
    'rate_limits' => [
        'guest' => ['daily_messages' => 10000],
        'registered' => ['daily_messages' => 10000],
    ],
    'mezzio-swoole' => [
        'swoole-http-server' => [
            'host' => '127.0.0.1',
            'port' => (int) (getenv('E2E_PORT') ?: 8094),
            'options' => [
                'pid_file' => $dataDir . '/swoole.pid',
            ],
        ],
    ],
];
