<?php

declare(strict_types=1);

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
];
