<?php

namespace App\Domain\Execucao\ValueObjects;

/**
 * De qual saga e de qual mensagem esta execução veio. É guardado junto da OS na
 * fila e depois na timeline: o mecânico puxa a OS e finaliza o reparo em outras
 * requisições, e os eventos execucao.* ainda precisam voltar ao orquestrador
 * dentro da mesma saga e do mesmo trace.
 *
 *  - sagaId      correlaciona os passos da saga (contrato v1.0, header.sagaId)
 *  - mensagemId  messageId do comando que originou a execução; vira o causationId dos eventos
 *  - traceparent contexto de trace (W3C) em que a execução foi recebida
 */
final class ContextoSaga
{
    public function __construct(
        public readonly string $sagaId,
        public readonly string $mensagemId,
        public readonly ?string $traceparent = null,
    ) {}

    /** trace-id (32 hex) do traceparent, para ligar a timeline ao trace distribuído. */
    public function traceId(): ?string
    {
        if ($this->traceparent !== null && preg_match('/^[\da-f]{2}-([\da-f]{32})-[\da-f]{16}-[\da-f]{2}$/', $this->traceparent, $m)) {
            return $m[1];
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'sagaId'      => $this->sagaId,
            'mensagemId'  => $this->mensagemId,
            'traceparent' => $this->traceparent,
        ];
    }

    public static function fromArray(?array $dados): ?self
    {
        if ($dados === null || !isset($dados['sagaId'], $dados['mensagemId'])) {
            return null;
        }

        return new self((string) $dados['sagaId'], (string) $dados['mensagemId'], $dados['traceparent'] ?? null);
    }
}
