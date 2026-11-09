<?php

namespace App\Domain\Execucao\Repositories;

use App\Domain\Execucao\Entities\ItemFila;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\ValueObjects\ContextoSaga;


interface FilaExecucaoRepositoryInterface
{
    /**
     * @throws ConflitoException se a OS já está na fila
     */
    public function enfileirar(string $osId, int $prioridade, string $execucaoId, \DateTimeImmutable $em, ?ContextoSaga $saga = null): ItemFila;

    /**
     * Tira a próxima OS da fila e a atribui ao mecânico, num passo atômico.
     * Devolve null com a fila vazia.
     *
     * @throws ConflitoException se o mecânico já está com uma OS em execução
     */
    public function proxima(string $mecanicoId): ?ItemFila;

    /**
     * Desfaz uma retirada: devolve o item à sua posição original e libera o mecânico.
     */
    public function devolver(ItemFila $item, string $mecanicoId): void;

    /**
     * @return ItemFila[] na ordem de atendimento (maior prioridade primeiro; mesma
     *                    prioridade, por chegada), com a posição (1 = próxima)
     */
    public function listar(): array;

    /** Posição na fila (1 = próxima) ou null se a OS não está na fila. */
    public function posicao(string $osId): ?int;

    /** Remove a OS da fila. Devolve false se ela não estava lá. */
    public function remover(string $osId): bool;

    /**
     * Tira a OS da fila e devolve o item (com o execucaoId e a saga), num passo atômico:
     * se um mecânico a puxar ao mesmo tempo, só um dos dois fica com ela. Devolve null se a
     * OS não estava na fila. É o que a compensação usa para cancelar uma execução que ainda
     * não começou.
     */
    public function retirar(string $osId): ?ItemFila;

    /** OS que o mecânico está executando agora, se houver. */
    public function osEmExecucao(string $mecanicoId): ?string;

    /**
     * Libera o mecânico ao fim do reparo. Idempotente: só libera se ele estiver
     * de fato com essa OS.
     */
    public function concluir(string $mecanicoId, string $osId): bool;
}
