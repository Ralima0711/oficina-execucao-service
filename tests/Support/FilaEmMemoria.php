<?php

namespace Tests\Support;

use App\Domain\Execucao\Entities\ItemFila;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;

/**
 * Fake em memória da fila: mesmas regras do Redis (FIFO, OS única, um reparo por
 * mecânico), para testar serviços e API sem infraestrutura.
 */
class FilaEmMemoria implements FilaExecucaoRepositoryInterface
{
    /** @var array<string, \DateTimeImmutable> osId => chegada, na ordem da fila */
    private array $fila = [];

    /** @var array<string, string> mecanicoId => osId */
    private array $emExecucao = [];

    public function enfileirar(string $osId, \DateTimeImmutable $em): ItemFila
    {
        if (isset($this->fila[$osId])) {
            throw new ConflitoException("A OS {$osId} já está na fila de execução.");
        }

        $this->fila[$osId] = $em;

        return new ItemFila($osId, $em);
    }

    public function proxima(string $mecanicoId): ?ItemFila
    {
        if (isset($this->emExecucao[$mecanicoId])) {
            throw new ConflitoException("O mecânico {$mecanicoId} já está executando a OS {$this->emExecucao[$mecanicoId]}.");
        }

        if ($this->fila === []) {
            return null;
        }

        $osId = array_key_first($this->fila);
        $item = new ItemFila($osId, $this->fila[$osId]);
        unset($this->fila[$osId]);
        $this->emExecucao[$mecanicoId] = $osId;

        return $item;
    }

    public function devolver(ItemFila $item, string $mecanicoId): void
    {
        unset($this->emExecucao[$mecanicoId]);
        $this->fila[$item->osId] = $item->enfileiradaEm;
        uasort($this->fila, fn ($a, $b) => $a <=> $b);
    }

    public function listar(): array
    {
        $itens = [];
        $posicao = 1;
        foreach ($this->fila as $osId => $em) {
            $itens[] = new ItemFila($osId, $em, $posicao++);
        }

        return $itens;
    }

    public function posicao(string $osId): ?int
    {
        $indice = array_search($osId, array_keys($this->fila), true);

        return $indice === false ? null : $indice + 1;
    }

    public function remover(string $osId): bool
    {
        $existia = isset($this->fila[$osId]);
        unset($this->fila[$osId]);

        return $existia;
    }

    public function osEmExecucao(string $mecanicoId): ?string
    {
        return $this->emExecucao[$mecanicoId] ?? null;
    }

    public function concluir(string $mecanicoId, string $osId): bool
    {
        if (($this->emExecucao[$mecanicoId] ?? null) !== $osId) {
            return false;
        }

        unset($this->emExecucao[$mecanicoId]);

        return true;
    }
}
