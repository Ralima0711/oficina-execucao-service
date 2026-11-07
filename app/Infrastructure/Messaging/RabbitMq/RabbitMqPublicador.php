<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\FalhaAoPublicarEvento;
use App\Application\Messaging\PublicadorDeEventosInterface;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Publica no exchange de eventos (oficina.eventos, topic) com confirmação do broker:
 * a mensagem é persistente, `mandatory` (se nenhuma fila estiver ligada à routing key
 * o broker a devolve) e só vale como publicada depois do ack do broker.
 */
class RabbitMqPublicador implements PublicadorDeEventosInterface
{
    public function __construct(private ConexaoRabbitMq $conexao) {}

    public function publicar(string $routingKey, Envelope $envelope): void
    {
        $exchange = (string) config('rabbitmq.exchange_eventos');

        try {
            $canal = $this->conexao->canal();
            $canal->confirm_select();

            $problemas = [];
            $canal->set_return_listener(function ($codigo, $texto, $exchange, $chave) use (&$problemas) {
                $problemas[] = "devolvida pelo broker ({$codigo} {$texto}) para a routing key {$chave}";
            });
            $canal->set_nack_handler(function () use (&$problemas) {
                $problemas[] = 'o broker não confirmou a mensagem (nack)';
            });

            $canal->basic_publish($this->mensagem($envelope), $exchange, $routingKey, true);
            $canal->wait_for_pending_acks_returns((float) config('rabbitmq.timeout'));
        } catch (\Throwable $e) {
            $this->conexao->fechar();

            throw new FalhaAoPublicarEvento("Falha ao publicar {$routingKey}: {$e->getMessage()}", 0, $e);
        }

        if ($problemas !== []) {
            throw new FalhaAoPublicarEvento("Falha ao publicar {$routingKey}: " . implode('; ', $problemas));
        }
    }

    private function mensagem(Envelope $envelope): AMQPMessage
    {
        // traceparent também nos headers do AMQP: quem consome pode ler de qualquer um dos dois.
        $headers = array_filter([
            'traceparent' => $envelope->traceparent,
            'sagaId'      => $envelope->sagaId,
            'causationId' => $envelope->causationId,
        ], fn ($v) => $v !== null);

        return new AMQPMessage($envelope->toJson(), [
            'content_type'        => 'application/json',
            'delivery_mode'       => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id'          => $envelope->messageId,
            'correlation_id'      => $envelope->sagaId,
            'type'                => $envelope->type,
            'timestamp'           => $envelope->occurredAt->getTimestamp(),
            'application_headers' => new AMQPTable($headers),
        ]);
    }
}
