<?php

return [

    'uri' => env('MONGO_URI', 'mongodb://127.0.0.1:27017'),

    'database' => env('MONGO_DATABASE', 'execucao'),

    // Quanto o driver espera por um servidor antes de falhar (a requisição não trava).
    'server_selection_timeout_ms' => env('MONGO_SERVER_SELECTION_TIMEOUT_MS', 5000),

    // Por quanto tempo lembrar os messageId já tratados (idempotência dos consumidores).
    'mensagens_processadas_ttl_dias' => env('MONGO_MENSAGENS_TTL_DIAS', 30),

];
