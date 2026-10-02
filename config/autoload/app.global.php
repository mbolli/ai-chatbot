<?php

declare(strict_types=1);

// .env values land in $_ENV; real process env (Docker, systemd) is only visible via getenv()
$env = static fn (string $key): ?string => $_ENV[$key] ?? (getenv($key) !== false ? getenv($key) : null);

return [
    'debug' => filter_var($env('APP_DEBUG') ?? false, FILTER_VALIDATE_BOOLEAN),

    'app' => [
        'name' => 'AI Chatbot',
        'env' => $env('APP_ENV') ?? 'production',
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
        'anthropic_api_key' => $env('ANTHROPIC_API_KEY') ?? null,
        'openai_api_key' => $env('OPENAI_API_KEY') ?? null,

        // Default model - Haiku 4.5 ($1/$5) / GPT-6 Luna ($0.10/$0.50) are cheapest
        'default_model' => $env('AI_DEFAULT_MODEL') ?? 'claude-haiku-4-5',

        // Max output tokens per response (cost control)
        // Haiku 4.5: $5/1M output tokens, so 2048 tokens = ~$0.01
        'max_tokens' => (int) ($env('AI_MAX_TOKENS') ?? 2048),

        // Estimated token budget for conversation history sent per request (system prompt and tools excluded).
        // Newest messages are kept whole, older ones dropped or truncated to fit.
        'context_max_tokens' => (int) ($env('AI_CONTEXT_MAX_TOKENS') ?? 8000),

        // Response format: 'markdown' (default) or 'plain'
        // Markdown responses are rendered with formatting (headers, code blocks, lists)
        // Plain responses are simpler text without heavy formatting
        'response_format' => $env('AI_RESPONSE_FORMAT') ?? 'markdown',
    ],

    // Rate limits - protect your budget! Hourly and token limits: 0 = unlimited.
    'rate_limits' => [
        'guest' => [
            'requests_per_hour' => (int) ($env('RATE_LIMIT_GUEST_HOURLY') ?? 10),
            'daily_messages' => (int) ($env('RATE_LIMIT_GUEST_DAILY') ?? 20),
            'daily_tokens' => (int) ($env('RATE_LIMIT_GUEST_DAILY_TOKENS') ?? 0),
        ],
        'registered' => [
            'requests_per_hour' => (int) ($env('RATE_LIMIT_USER_HOURLY') ?? 30),
            'daily_messages' => (int) ($env('RATE_LIMIT_USER_DAILY') ?? 100),
            'daily_tokens' => (int) ($env('RATE_LIMIT_USER_DAILY_TOKENS') ?? 0),
        ],
    ],
];
