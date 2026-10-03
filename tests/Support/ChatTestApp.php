<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Container;
use App\Domain\Model\User;
use App\Web\ChatApp;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\PatchMode;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Testing\TestTab;
use Mbolli\PhpVia\Via;
use OpenSwoole\Event;

/**
 * The chat app as bin/server.php builds it, on an in-memory database, served in-process by php-via's TestApp.
 */
final class ChatTestApp {
    public static function container(string $env): Container {
        $container = new Container(['database' => ['path' => ':memory:'], 'app' => ['env' => $env]], \dirname(__DIR__, 2));
        $container->pdo()->exec((string) file_get_contents(\dirname(__DIR__, 2) . '/data/schema.sql'));

        return $container;
    }

    public static function create(Container $container): TestApp {
        return new TestApp((new Config())->withLogLevel('error'), static function (Via $via) use ($container): void {
            $container->eventBus()->attach($via);
            (new ChatApp($via, $container))->register();
        });
    }

    /**
     * Shut $app down and drain the event loop its stream cleanup timers started outside a reactor: on OpenSwoole 26.2
     * a later Coroutine::run() in this process, as MessageCommandHandlerTest runs, segfaults otherwise.
     */
    public static function stop(TestApp $app): void {
        $app->shutdown();
        Event::wait();
    }

    /**
     * A new browser on the home page whose session is signed in as $user, without going through the sign-in dialog.
     */
    public static function browserOf(TestApp $app, User $user): TestTab {
        $home = $app->open('/', connect: false);
        $app->via()->setSessionData((string) $home->context()->getSessionId(), 'authenticated_user', $user->id);

        return $home;
    }

    /**
     * The user id the session of $tab is signed in as.
     */
    public static function sessionUser(TestApp $app, TestTab $tab): mixed {
        return $app->via()->getSessionData((string) $tab->context()->getSessionId(), 'authenticated_user');
    }

    /**
     * The scripts execScript() sent among $patches, in order.
     *
     * @param list<array{type: string, html?: string, selector?: null|string, mode?: PatchMode}> $patches
     *
     * @return list<string>
     */
    public static function scripts(array $patches): array {
        $scripts = [];
        foreach ($patches as $patch) {
            if (($patch['selector'] ?? null) === 'body' && ($patch['mode'] ?? null) === PatchMode::Append
                && preg_match('#^<script[^>]*>(.*)</script>$#s', $patch['html'] ?? '', $match) === 1) {
                $scripts[] = $match[1];
            }
        }

        return $scripts;
    }
}
