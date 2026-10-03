<?php

declare(strict_types=1);

use App\Container;
use App\Web\ChatApp;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;

$root = dirname(__DIR__);
chdir($root);

require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

/** @var array<string, mixed> $config */
$config = [];
foreach ([...glob($root . '/config/autoload/*.global.php') ?: [], ...glob($root . '/config/autoload/*.local.php') ?: []] as $file) {
    $config = array_replace_recursive($config, require $file);
}
if (getenv('E2E_DATA_DIR') !== false) {
    $config = array_replace_recursive($config, require $root . '/config/e2e.php');
}

$container = new Container($config, $root);
// Local configs written before the php-via port still use the old mezzio-swoole key
$server = ($config['server'] ?? []) + ($config['mezzio-swoole']['swoole-http-server'] ?? []);

$viaConfig = (new Config())
    ->withHost((string) ($server['host'] ?? '0.0.0.0'))
    ->withPort((int) ($server['port'] ?? 8080))
    ->withDevMode((bool) ($config['debug'] ?? false))
    ->withLogLevel(($config['debug'] ?? false) ? 'debug' : 'warning')
    // php-via's Dev Bar overlays the page; opt in with VIA_DEVBAR=1
    ->withDevBar(filter_var(getenv('VIA_DEVBAR') ?: ($_ENV['VIA_DEVBAR'] ?? false), FILTER_VALIDATE_BOOLEAN))
    ->withStaticDir($root . '/public')
    // Asset URLs carry the file's mtime, so they can be cached for a year
    ->withStaticCacheControl(static fn (string $file, string $mime): string => in_array($mime, ['text/css', 'text/javascript', 'application/javascript'], true)
        ? 'public, max-age=31536000'
        : 'public, max-age=3600, must-revalidate')
;

if ($container->isProduction()) {
    $viaConfig->withSecureCookie(true);
}
// php-via compresses the SSE stream itself; needs ext-brotli and a proxy speaking h2c (Caddy: reverse_proxy h2c://…)
if ((bool) ($server['brotli'] ?? false)) {
    $viaConfig->withH2c()->withBrotli();
}

$app = new Via($viaConfig);
$container->eventBus()->attach($app);

(new ChatApp($app, $container))->register();

// Streams whose coroutine died never end their session; guests without chats are dropped hourly
$app->setInterval(static function () use ($container): void {
    $container->streamingSessions()->cleanupStaleSessions();
}, 30_000);
$app->setInterval(static function () use ($container): void {
    $removed = $container->users()->cleanupOrphanedGuests();
    if ($removed > 0) {
        error_log("[Cleanup] Removed {$removed} orphaned guest users");
    }
}, 3_600_000);

$app->start();
