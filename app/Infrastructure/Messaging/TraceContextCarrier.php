<?php

namespace App\Infrastructure\Messaging;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;

/**
 * CAMADA DE INFRAESTRUTURA — Mensageria
 *
 * Leva o contexto de trace (W3C Trace Context) nos headers de uma mensagem:
 *
 *   publicar:  $headers = TraceContextCarrier::inject();          // traceparent[, tracestate]
 *   consumir:  $contexto = TraceContextCarrier::extract($headers);
 *
 * Os headers são um array simples (chave => string), então servem a qualquer
 * broker: `application_headers` no RabbitMQ, message attributes no SQS,
 * headers no Kafka. Contrato: docs/contrato-observabilidade.md.
 */
final class TraceContextCarrier
{
    public const TRACEPARENT = 'traceparent';
    public const TRACESTATE = 'tracestate';

    /**
     * Acrescenta ao array de headers o contexto de trace do span corrente (ou
     * de $contexto). Sem span ativo, devolve os headers como vieram.
     *
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    public static function inject(array $headers = [], ?ContextInterface $contexto = null): array
    {
        TraceContextPropagator::getInstance()->inject(
            $headers,
            ArrayAccessGetterSetter::getInstance(),
            $contexto ?? Context::getCurrent()
        );

        return $headers;
    }

    /**
     * Lê o contexto de trace dos headers. A busca ignora maiúsculas/minúsculas
     * (brokers variam) e parte sempre da raiz: um traceparent ausente ou
     * inválido gera um trace novo, nunca herda o span ambiente do worker.
     *
     * @param array<string, mixed> $headers
     */
    public static function extract(array $headers): ContextInterface
    {
        return TraceContextPropagator::getInstance()->extract(
            self::normalizar($headers),
            ArrayAccessGetterSetter::getInstance(),
            Context::getRoot()
        );
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function normalizar(array $headers): array
    {
        $normalizados = [];

        foreach ($headers as $chave => $valor) {
            if (is_array($valor)) {
                $valor = $valor[0] ?? null;
            }

            if (is_string($valor)) {
                $normalizados[strtolower((string) $chave)] = $valor;
            }
        }

        return $normalizados;
    }
}
