<?php

namespace App\Domain\Execucao\Entities;

use App\Domain\Execucao\ValueObjects\ContextoSaga;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;

final class EventoTimeline implements \JsonSerializable
{
    public function __construct(
        public readonly string $osId,
        public readonly TipoEvento $tipo,
        public readonly ?StatusExecucao $statusAnterior,
        public readonly ?StatusExecucao $statusNovo,
        public readonly ?string $mecanicoId,
        public readonly ?int $duracaoSegundos,
        public readonly ?string $observacao,
        public readonly ?string $traceId,
        public readonly \DateTimeImmutable $ocorridoEm,
        public readonly ?string $execucaoId = null,
        public readonly ?ContextoSaga $saga = null,
    ) {}

    public function toArray(): array
    {
        return [
            'osId'            => $this->osId,
            'execucaoId'      => $this->execucaoId,
            'sagaId'          => $this->saga?->sagaId,
            'tipo'            => $this->tipo->value,
            'statusAnterior'  => $this->statusAnterior?->value,
            'statusNovo'      => $this->statusNovo?->value,
            'mecanicoId'      => $this->mecanicoId,
            'duracaoSegundos' => $this->duracaoSegundos,
            'observacao'      => $this->observacao,
            'traceId'         => $this->traceId,
            'ocorridoEm'      => $this->ocorridoEm
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
