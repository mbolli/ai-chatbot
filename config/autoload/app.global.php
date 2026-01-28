<?php

declare(strict_types=1);

use App\Infrastructure\Http\Listener\SseRequestListener;
use Laminas\Stdlib\ArrayUtils\MergeReplaceKey;
use Mezzio\Swoole\Event\RequestEvent;
use Mezzio\Swoole\Event\RequestHandlerRequestListener;
use Mezzio\Swoole\Event\StaticResourceRequestListener;

return [
    'debug' => false,

    'app' => [
        'name' => 'AI Chatbot',
        'env' => 'production',
    ],

    'database' => [
        'path' => getcwd() . '/data/db.sqlite',
    ],

    'ai' => [
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 8192,
    ],

    'rate_limits' => [
        'guest' => [
            'daily_messages' => 20,
        ],
        'registered' => [
            'daily_messages' => 100,
        ],
    ],

    'templates' => [
        'paths' => [
            'app' => ['templates/app'],
            'layout' => ['templates/layout'],
            'partials' => ['templates/partials'],
            'error' => ['templates/error'],
        ],
    ],

    'mezzio-swoole' => [
        'swoole-http-server' => [
            'host' => '0.0.0.0',
            'port' => 8080,
            'options' => [
                'worker_num' => 1, // swoole_cpu_num(),
                'enable_coroutine' => true,
                'pid_file' => getcwd() . '/data/swoole.pid',
            ],
            'static-files' => [
                'enable' => true,
                'document-root' => getcwd() . '/public',
                'type-map' => [
                    'css' => 'text/css',
                    'js' => 'application/javascript',
                    'map' => 'application/json',
                ],
                'directives' => [
                    '/\.(css|js|map)$/' => [
                        'cache-control' => ['public', 'max-age=31536000'],
                        'last-modified' => true,
                        'etag' => true,
                    ],
                ],
            ],
            'listeners' => [
                // SSE listener MUST run before RequestHandlerRequestListener
                // to intercept /updates and handle SSE streaming.
                // Using MergeReplaceKey to override the default listener order.
                RequestEvent::class => new MergeReplaceKey([
                    StaticResourceRequestListener::class,
                    SseRequestListener::class,
                    RequestHandlerRequestListener::class,
                ]),
            ],
        ],
    ],
];
