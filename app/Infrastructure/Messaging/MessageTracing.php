<?php

namespace App\Infrastructure\Messaging;

use App\Infrastructure\Observability\OpenTelemetryProvider;
use App\Infrastructure\Observability\Tracing;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * CAMADA DE INFRAESTRUTURA — Mensageria
 *
 * Instrumenta publicação e consumo de mensagens, igual nos 3 serviços:
 *
 *   MessageTracing::publicar('execucao.finalizada', function (array $headers) use ($payload) {
 *       $broker->publish($payload, $headers);   // $headers já leva o traceparent
 *   });
 *
 *   MessageTracing::consumir('execucao.enfileirar', $headers, function () use ($payload) {
 *       $casoDeUso->executar($payload);         // spans e logs aqui dentro ficam no trace
 *   });
 *
 * publicar  → span PRODUCER "<destino> publish"
 * consumir  → span CONSUMER "<origem> process", filho do traceparent recebido
 */
final class MessageTracing
{
    /**
     * @param callable(array<string, mixed>): mixed $publicar recebe os headers com o traceparent
     * @param array<string, mixed>                  $atributos atributos extras do span (ex.: os.id)
     */
    public static function publicar(string $destino, callable $publicar, array $atributos = []): mixed
    {
        $span = Tracing::iniciar("{$destino} publish", $atributos + [
            'messaging.destination.name' => $destino,
            'messaging.operation' => 'publish',
        ], SpanKind::KIND_PRODUCER);

        try {
            // O span PRODUCER é o corrente: o consumer vira filho dele.
            $resultado = $publicar(TraceContextCarrier::inject());

            Tracing::sucesso($span);

            return $resultado;
        } catch (\Throwable $e) {
            Tracing::erro($span, $e);

            throw $e;
        } finally {
            Tracing::finalizar($span);
        }
    }

    /**
     * @param array<string, mixed> $headers   headers da mensagem recebida
     * @param callable(): mixed    $processar caso de uso; roda dentro do span CONSUMER
     * @param array<string, mixed> $atributos atributos extras do span (ex.: os.id)
     */
    public static function consumir(string $origem, array $headers, callable $processar, array $atributos = []): mixed
    {
        $span = Tracing::iniciar("{$origem} process", $atributos + [
            'messaging.destination.name' => $origem,
            'messaging.operation' => 'process',
        ], SpanKind::KIND_CONSUMER, TraceContextCarrier::extract($headers));

        try {
            $resultado = $processar();

            Tracing::sucesso($span);

            Log::info('mensagem_processada', ['messaging.destination.name' => $origem]);

            return $resultado;
        } catch (\Throwable $e) {
            Tracing::erro($span, $e);

            Log::error('falha_ao_processar_mensagem', [
                'messaging.destination.name' => $origem,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            Tracing::finalizar($span);

            // Worker é processo longo: sem flush por mensagem os spans só saem no shutdown.
            OpenTelemetryProvider::flush();
        }
    }
}
