<?php

namespace App\Domain\Execucao\Repositories;

use App\Domain\Execucao\Entities\ItemFila;
use App\Domain\Execucao\Exceptions\ConflitoException;


interface FilaExecucaoRepositoryInterface
{
    /**
     * @throws ConflitoException se a OS já está na fila
     */
    public function enfileirar(int $osId, \DateTimeImmutable $em): ItemFila;

    /**
     * Tira a próxima OS da fila e a atribui ao mecânico, num passo atômico.
     * Devolve null com a fila vazia.
     *
     * @throws ConflitoException se o mecânico já está com uma OS em execução
     */
    public function proxima(int $mecanicoId): ?ItemFila;

    /**
     * Desfaz uma retirada: devolve o item à sua posição original e libera o mecânico.
     */
    public function devolver(ItemFila $item, int $mecanicoId): void;

    /**
     * @return ItemFila[] em ordem de chegada, com a posição (1 = próxima)
     */
    public function listar(): array;

    /** Posição na fila (1 = próxima) ou null se a OS não está na fila. */
    public function posicao(int $osId): ?int;

    /** Remove a OS da fila. Devolve false se ela não estava lá. */
    public function remover(int $osId): bool;

    /** OS que o mecânico está executando agora, se houver. */
    public function osEmExecucao(int $mecanicoId): ?int;

    /**
     * Libera o mecânico ao fim do reparo. Idempotente: só libera se ele estiver
     * de fato com essa OS.
     */
    public function concluir(int $mecanicoId, int $osId): bool;
}
