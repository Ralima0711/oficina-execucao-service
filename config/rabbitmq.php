<?php

return [

    'host'     => env('RABBITMQ_HOST', '127.0.0.1'),
    'port'     => (int) env('RABBITMQ_PORT', 5672),
    'user'     => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost'    => env('RABBITMQ_VHOST', '/'),

    // Segundos esperando o broker ao conectar (a requisição HTTP não trava se ele cair).
    'timeout' => (float) env('RABBITMQ_TIMEOUT', 3),

    // Topologia do contrato (declarada em definitions.json; aqui só os nomes).
    'exchange_eventos'   => env('RABBITMQ_EXCHANGE_EVENTOS', 'oficina.eventos'),
    'fila_enviar'        => env('RABBITMQ_FILA_ENVIAR', 'execucao.enviar-para-execucao'),
    'fila_cancelar'      => env('RABBITMQ_FILA_CANCELAR', 'execucao.cancelar-execucao'),

    // Uma mensagem por vez: a seguinte só chega depois do ack da atual.
    'prefetch' => (int) env('RABBITMQ_PREFETCH', 1),

];
