<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MensagemInvalida;
use App\Application\Services\EnviarParaExecucaoHandler;
use App\Infrastructure\Messaging\MessageTracing;
use App\Infrastructure\Messaging\TraceContextCarrier;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consome execucao.enviar-para-execucao, uma mensagem por vez, com ack manual:
 *
 *   - tratada (ou repetição descartada pela idempotência) → ack
 *   - fora do contrato, ou falha ao tratar              → nack sem requeue → DLQ (oficina.dlx)
 *
 * Cada mensagem roda num span CONSUMER filho do traceparent recebido.
 */
class ConsumidorDeComandos
{
    public function __construct(
        private ConexaoRabbitMq $conexao,
        private EnviarParaExecucaoHandler $handler,
    ) {}

    /**
     * @param int|null   $limite          para depois de tratar N mensagens (sem limite: roda para sempre)
     * @param float|null $ociosoSegundos  para depois de tanto tempo sem mensagem (sem limite: espera para sempre)
     *
     * @return int mensagens tratadas
     */
    public function executar(?int $limite = null, ?float $ociosoSegundos = null): int
    {
        $canal = $this->conexao->canal();
        $canal->basic_qos(0, (int) config('rabbitmq.prefetch'), false);

        $tratadas = 0;
        $tag = $canal->basic_consume(
            (string) config('rabbitmq.fila_enviar'),
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $mensagem) use (&$tratadas) {
                $this->processar($mensagem);
                $tratadas++;
            }
        );

        $desde = microtime(true);

        while ($canal->is_consuming() && ($limite === null || $tratadas < $limite)) {
            try {
                $canal->wait(null, false, 1.0);
                $desde = microtime(true);
            } catch (AMQPTimeoutException) {
                if ($ociosoSegundos !== null && microtime(true) - $desde >= $ociosoSegundos) {
                    break;
                }
            }
        }

        $canal->basic_cancel($tag);

        return $tratadas;
    }

    public function processar(AMQPMessage $mensagem): void
    {
        $fila = (string) config('rabbitmq.fila_enviar');

        try {
            $envelope = Envelope::fromJson($mensagem->getBody(), $this->cabecalhos($mensagem));
        } catch (MensagemInvalida $e) {
            Log::warning('mensagem_invalida_enviada_para_dlq', ['fila' => $fila, 'motivo' => $e->getMessage()]);
            $mensagem->nack(false);

            return;
        }

        try {
            MessageTracing::consumir(
                $fila,
                [TraceContextCarrier::TRACEPARENT => $envelope->traceparent],
                fn () => $this->handler->handle($envelope),
                ['saga.id' => $envelope->sagaId, 'messaging.message.id' => $envelope->messageId]
            );

            $mensagem->ack();
        } catch (MensagemInvalida $e) {
            Log::warning('comando_invalido_enviado_para_dlq', [
                'fila' => $fila, 'message.id' => $envelope->messageId, 'saga.id' => $envelope->sagaId, 'motivo' => $e->getMessage(),
            ]);
            $mensagem->nack(false);
        } catch (\Throwable) {
            // MessageTracing::consumir já registrou o erro (log e span).
            $mensagem->nack(false);
        }
    }

    /** @return array<string, mixed> */
    private function cabecalhos(AMQPMessage $mensagem): array
    {
        if (!$mensagem->has('application_headers')) {
            return [];
        }

        return $mensagem->get('application_headers')->getNativeData();
    }
}
