<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Conexão e canal do RabbitMQ, abertos sob demanda e reaproveitados enquanto
 * estiverem de pé. A API usa uma conexão curta por requisição; o worker mantém
 * a sua aberta.
 */
class ConexaoRabbitMq
{
    private ?AMQPStreamConnection $conexao = null;

    private ?AMQPChannel $canal = null;

    public function canal(): AMQPChannel
    {
        if ($this->canal !== null && $this->canal->is_open() && $this->conexao?->isConnected()) {
            return $this->canal;
        }

        $this->fechar();

        $timeout = (float) config('rabbitmq.timeout');

        $this->conexao = new AMQPStreamConnection(
            (string) config('rabbitmq.host'),
            (int) config('rabbitmq.port'),
            (string) config('rabbitmq.user'),
            (string) config('rabbitmq.password'),
            (string) config('rabbitmq.vhost'),
            connection_timeout: $timeout,
            read_write_timeout: max($timeout, 3.0),
        );
        $this->canal = $this->conexao->channel();

        return $this->canal;
    }

    public function fechar(): void
    {
        try {
            $this->canal?->close();
            $this->conexao?->close();
        } catch (\Throwable) {
            // Já estava fechada ou o broker caiu: não há o que fechar.
        }

        $this->canal = null;
        $this->conexao = null;
    }

    public function __destruct()
    {
        $this->fechar();
    }
}
