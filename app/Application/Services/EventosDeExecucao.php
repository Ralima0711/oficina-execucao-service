<?php

namespace App\Application\Services;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\PublicadorDeEventosInterface;
use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\ValueObjects\ContextoSaga;
use App\Infrastructure\Messaging\MessageTracing;
use App\Infrastructure\Messaging\TraceContextCarrier;
use Illuminate\Support\Facades\Log;

/**
 * Publica os eventos execucao.* do contrato v1.0 de volta ao orquestrador.
 *
 * Só publica se a execução veio de uma saga (comando execucao.enviar-para-execucao):
 * uma OS enfileirada direto pela API REST não tem saga a quem responder.
 *
 * O span PRODUCER nasce como filho do trace em que o comando foi recebido, e não
 * do da requisição HTTP do mecânico: o mecânico puxa a OS e finaliza o reparo em
 * outras requisições, mas os eventos continuam no trace da saga.
 */
class EventosDeExecucao
{
    public function __construct(private PublicadorDeEventosInterface $publicador) {}

    public function iniciada(EventoTimeline $evento): void
    {
        $this->publicar('execucao.iniciada', $evento->saga, [
            'osId'       => $evento->osId,
            'execucaoId' => $evento->execucaoId,
            'mecanicoId' => $evento->mecanicoId,
            'iniciadaEm' => self::iso($evento->ocorridoEm),
        ]);
    }

    public function concluida(EventoTimeline $evento, \DateTimeImmutable $iniciadaEm): void
    {
        $this->publicar('execucao.concluida', $evento->saga, [
            'osId'        => $evento->osId,
            'execucaoId'  => $evento->execucaoId,
            'mecanicoId'  => $evento->mecanicoId,
            'iniciadaEm'  => self::iso($iniciadaEm),
            'concluidaEm' => self::iso($evento->ocorridoEm),
        ]);
    }

    /** @param array<string, mixed> $corpo */
    private function publicar(string $routingKey, ?ContextoSaga $saga, array $corpo): void
    {
        if ($saga === null) {
            Log::info('evento_nao_publicado_sem_saga', ['evento' => $routingKey, 'os.id' => $corpo['osId']]);

            return;
        }

        $pai = $saga->traceparent !== null
            ? TraceContextCarrier::extract([TraceContextCarrier::TRACEPARENT => $saga->traceparent])
            : null;

        MessageTracing::publicar($routingKey, function (array $headers) use ($routingKey, $saga, $corpo) {
            $this->publicador->publicar(
                $routingKey,
                Envelope::novo($routingKey, $saga->sagaId, $saga->mensagemId, $corpo, $headers[TraceContextCarrier::TRACEPARENT] ?? null)
            );
        }, ['os.id' => $corpo['osId'], 'saga.id' => $saga->sagaId], $pai);

        Log::info('evento_publicado', [
            'evento'      => $routingKey,
            'os.id'       => $corpo['osId'],
            'execucao.id' => $corpo['execucaoId'],
            'saga.id'     => $saga->sagaId,
        ]);
    }

    private static function iso(\DateTimeImmutable $data): string
    {
        return $data->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
