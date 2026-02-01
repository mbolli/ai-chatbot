<?php

declare(strict_types=1);

use App\Infrastructure\Http\Listener\CleanupTimerListener;
use App\Infrastructure\Http\Listener\SseRequestListener;
use Laminas\Stdlib\ArrayUtils\MergeReplaceKey;
use Mezzio\Swoole\Event\RequestEvent;
use Mezzio\Swoole\Event\RequestHandlerRequestListener;
use Mezzio\Swoole\Event\StaticResourceRequestListener;
use Mezzio\Swoole\Event\WorkerStartEvent;
use Mezzio\Swoole\Event\WorkerStartListener;

return [
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),

    'app' => [
        'name' => 'AI Chatbot',
        'env' => $_ENV['APP_ENV'] ?? 'production',
    ],

    'database' => [
        'path' => getcwd() . '/data/db.sqlite',
    ],

    // AI Configuration - tune these for cost control
    // NOTE: In production mode (APP_ENV=production), only cost-effective models are available:
    //   - Anthropic: claude-3-haiku-20240307, claude-3-5-haiku-20241022, claude-haiku-4-5
    //   - OpenAI: gpt-5-nano, gpt-5-mini, gpt-4o-mini, gpt-4.1-nano, gpt-4.1-mini
    'ai' => [
        // API Keys (required - set in .env)
        'anthropic_api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? null,
        'openai_api_key' => $_ENV['OPENAI_API_KEY'] ?? null,

        // Default model - Haiku 3 / GPT-5 Nano are cheapest!
        // Anthropic: claude-3-haiku-20240307 ($0.25/$1.25), claude-haiku-4-5 ($1/$5)
        // OpenAI: gpt-5-nano ($0.05/$0.40), gpt-5 ($1.25/$10)
        'default_model' => $_ENV['AI_DEFAULT_MODEL'] ?? 'claude-3-haiku-20240307',

        // Max output tokens per response (cost control)
        // Haiku 3: ~$1.25/1M output tokens, so 2048 tokens = ~$0.0025
        'max_tokens' => (int) ($_ENV['AI_MAX_TOKENS'] ?? 2048),

        // Context compression - reduce token usage for long conversations
        // Number of recent messages to keep in full
        'context_recent_messages' => (int) ($_ENV['AI_CONTEXT_RECENT_MESSAGES'] ?? 6),
        // Max chars for older messages before truncation (~4 chars = 1 token)
        'context_max_older_chars' => (int) ($_ENV['AI_CONTEXT_MAX_OLDER_CHARS'] ?? 500),

        // Response format: 'markdown' (default) or 'plain'
        // Markdown responses are rendered with formatting (headers, code blocks, lists)
        // Plain responses are simpler text without heavy formatting
        'response_format' => $_ENV['AI_RESPONSE_FORMAT'] ?? 'markdown',
    ],

    // Rate limits - protect your budget!
    'rate_limits' => [
        'guest' => [
            'requests_per_hour' => (int) ($_ENV['RATE_LIMIT_GUEST_HOURLY'] ?? 10),
            'daily_messages' => (int) ($_ENV['RATE_LIMIT_GUEST_DAILY'] ?? 20),
        ],
        'registered' => [
            'requests_per_hour' => (int) ($_ENV['RATE_LIMIT_USER_HOURLY'] ?? 30),
            'daily_messages' => (int) ($_ENV['RATE_LIMIT_USER_DAILY'] ?? 100),
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
                // Worker start listener for cleanup timers
                WorkerStartEvent::class => new MergeReplaceKey([
                    WorkerStartListener::class,
                    CleanupTimerListener::class,
                ]),
            ],
        ],
    ],
];
