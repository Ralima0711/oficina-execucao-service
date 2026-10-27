<?php

namespace App\Domain\Execucao\Entities;


final class ItemFila implements \JsonSerializable
{
    public function __construct(
        public readonly int $osId,
        public readonly \DateTimeImmutable $enfileiradaEm,
        public readonly ?int $posicao = null,
    ) {}

    public function comPosicao(?int $posicao): self
    {
        return new self($this->osId, $this->enfileiradaEm, $posicao);
    }

    public function toArray(): array
    {
        return [
            'osId'          => $this->osId,
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
