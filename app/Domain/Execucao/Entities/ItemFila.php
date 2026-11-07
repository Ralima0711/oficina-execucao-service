<?php

namespace App\Domain\Execucao\Entities;

final class ItemFila implements \JsonSerializable
{
    public const PRIORIDADE_MINIMA = 1;
    public const PRIORIDADE_MAXIMA = 5;

    /**
     * Maior prioridade é mais urgente: o contrato v1.0 traz `prioridade` (padrão 1)
     * sem definir o sentido; esta é a premissa até a revisão do contrato.
     */
    public function __construct(
        public readonly string $osId,
        public readonly \DateTimeImmutable $enfileiradaEm,
        public readonly int $prioridade = self::PRIORIDADE_MINIMA,
        public readonly ?string $execucaoId = null,
        public readonly ?int $posicao = null,
    ) {}

    public function comPosicao(?int $posicao): self
    {
        return new self($this->osId, $this->enfileiradaEm, $this->prioridade, $this->execucaoId, $posicao);
    }

    public function toArray(): array
    {
        return [
            'osId'          => $this->osId,
            'execucaoId'    => $this->execucaoId,
            'prioridade'    => $this->prioridade,
            'posicao'       => $this->posicao,
            'enfileiradaEm' => $this->enfileiradaEm
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
