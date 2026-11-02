<?php

use App\Infrastructure\Observability\ContextoObservabilidadeProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        // Log estruturado em JSON no stdout — formato consumido pelo New Relic
        // (logs in context) e por coletores em Kubernetes. É o canal padrão dos
        // 3 serviços (ver docs/contrato-observabilidade.md).
        'stdout_json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stdout',
            ],
            'formatter' => JsonFormatter::class,
            'processors' => [
                PsrLogMessageProcessor::class,
                ContextoObservabilidadeProcessor::class,
            ],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],

    ],

];
