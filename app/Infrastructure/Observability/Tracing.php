<?php

namespace App\Infrastructure\Observability;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;


class Tracing
{
    /**
     * @param int                   $tipo SpanKind::KIND_* (INTERNAL por padrão)
     * @param ContextInterface|null $pai  contexto pai explícito (ex.: extraído
     *                                    de uma mensagem); sem ele, vale o corrente
     */
    public static function iniciar(
        string $nome,
        array $atributos = [],
        int $tipo = SpanKind::KIND_INTERNAL,
        ?ContextInterface $pai = null,
    ): ?SpanInterface {
        if (!class_exists(Span::class)) {
            return null;
        }

        $builder = OpenTelemetryProvider::tracer()
            ->spanBuilder($nome)
            ->setSpanKind($tipo);

        foreach ($atributos as $chave => $valor) {
            $builder->setAttribute($chave, $valor);
        }

        if ($pai !== null) {
            $builder->setParent($pai);
        } else {
            $atual = Span::fromContext(Context::getCurrent())->getContext();
            if ($atual->isValid()) {
                $builder->setParent(Context::getCurrent());
            }
        }

        $span = $builder->startSpan();

        // Torna o span corrente para que spans internos virem filhos dele.
        self::$escopos[] = $span->activate();

        return $span;
    }

    /**
     * Roda $operacao dentro de um span (sucesso, erro e encerramento incluídos).
     *
     * @template T
     *
     * @param callable(): T $operacao
     *
     * @return T
     */
    public static function executar(string $nome, array $atributos, callable $operacao): mixed
    {
        $span = self::iniciar($nome, $atributos);

        try {
            $resultado = $operacao();

            self::sucesso($span);

            return $resultado;
        } catch (\Throwable $e) {
            self::erro($span, $e);

            throw $e;
        } finally {
            self::finalizar($span);
        }
    }

    /** trace.id do span ativo, ou null fora de um trace. */
    public static function traceIdAtual(): ?string
    {
        if (!class_exists(Span::class)) {
            return null;
        }

        $contexto = Span::getCurrent()->getContext();

        return $contexto->isValid() ? $contexto->getTraceId() : null;
    }

    public static function sucesso(?SpanInterface $span): void
    {
        $span?->setStatus(StatusCode::STATUS_OK);
    }

    public static function erro(?SpanInterface $span, \Throwable $e): void
    {
        if ($span === null) {
            return;
        }

        $span->recordException($e);
        $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
    }

    public static function finalizar(?SpanInterface $span): void
    {
        if ($span === null) {
            return;
        }

        $escopo = array_pop(self::$escopos);
        if ($escopo instanceof ScopeInterface) {
            $escopo->detach();
        }

        $span->end();
    }

    /** @var ScopeInterface[] */
    private static array $escopos = [];
}
