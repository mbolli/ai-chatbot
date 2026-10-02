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
    //   - Anthropic: claude-haiku-4-5
    //   - OpenAI: gpt-6-luna, gpt-4o-mini, gpt-5.6-luna, gpt-4.1-mini
    'ai' => [
        // API Keys (required - set in .env)
        'anthropic_api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? null,
        'openai_api_key' => $_ENV['OPENAI_API_KEY'] ?? null,

        // Default model - Haiku 4.5 ($1/$5) / GPT-6 Luna ($0.10/$0.50) are cheapest
        'default_model' => $_ENV['AI_DEFAULT_MODEL'] ?? 'claude-haiku-4-5',

        // Max output tokens per response (cost control)
        // Haiku 4.5: $5/1M output tokens, so 2048 tokens = ~$0.01
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

    // Rate limits - protect your budget! Hourly and token limits: 0 = unlimited.
    'rate_limits' => [
        'guest' => [
            'requests_per_hour' => (int) ($_ENV['RATE_LIMIT_GUEST_HOURLY'] ?? 10),
            'daily_messages' => (int) ($_ENV['RATE_LIMIT_GUEST_DAILY'] ?? 20),
            'daily_tokens' => (int) ($_ENV['RATE_LIMIT_GUEST_DAILY_TOKENS'] ?? 0),
        ],
        'registered' => [
            'requests_per_hour' => (int) ($_ENV['RATE_LIMIT_USER_HOURLY'] ?? 30),
            'daily_messages' => (int) ($_ENV['RATE_LIMIT_USER_DAILY'] ?? 100),
            'daily_tokens' => (int) ($_ENV['RATE_LIMIT_USER_DAILY_TOKENS'] ?? 0),
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
