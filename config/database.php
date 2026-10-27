<?php

/*
| Este serviço não usa banco SQL: a fila fica no Redis e a timeline no MongoDB
| (config/mongodb.php). Só o Redis é configurado aqui.
*/
return [

    'default' => env('DB_CONNECTION'),

    'connections' => [],

    'migrations' => [
        'table' => 'migrations',
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        // Prefixo vazio: as chaves são literais (execucao:fila, execucao:mecanico:{id}:atual).
        'options' => [
            'prefix' => env('REDIS_PREFIX', ''),
        ],

        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

    ],

];
