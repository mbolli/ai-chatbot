<?php

declare(strict_types=1);

/**
 * OpenSwoole compatibility layer.
 *
 * Provides polyfills for Swoole functions that don't exist in OpenSwoole.
 * This file should be loaded before any Swoole/OpenSwoole code runs.
 */

// Polyfill for swoole_set_process_name() which doesn't exist in OpenSwoole
if (!function_exists('swoole_set_process_name')) {
    function swoole_set_process_name(string $name): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS doesn't support process renaming
            return false;
        }

        if (function_exists('cli_set_process_title')) {
            return @cli_set_process_title($name);
        }

        return false;
    }
}
