<?php

declare(strict_types=1);

use Mezzio\Swoole\Log\AccessLogInterface;
use Mezzio\Swoole\Log\Psr3AccessLogDecorator;
use Mezzio\Swoole\Log\StdoutLogger;

return [
    'mezzio-swoole' => [
        'swoole-http-server' => [
            'host' => '0.0.0.0',
            'port' => 8080,
            'mode' => SWOOLE_PROCESS,
            'protocol' => SWOOLE_SOCK_TCP,
            'options' => [
                'worker_num' => swoole_cpu_num(),
                'task_worker_num' => swoole_cpu_num(),
                'enable_coroutine' => true,
                'max_coroutine' => 100000,
                'document_root' => getcwd() . '/public',
                'enable_static_handler' => true,
                'static_handler_locations' => ['/css', '/js', '/images'],
            ],
        ],
        'enable-coroutine' => true,
    ],
    'dependencies' => [
        'invokables' => [
            StdoutLogger::class => StdoutLogger::class,
        ],
        'factories' => [
            AccessLogInterface::class => fn ($container) => new Psr3AccessLogDecorator(
                $container->get(StdoutLogger::class)
            ),
        ],
    ],
];
