<?php

namespace App\Infrastructure\Persistence\Redis;

use App\Domain\Execucao\Entities\ItemFila;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;
use Illuminate\Support\Facades\Redis;


class RedisFilaExecucaoRepository implements FilaExecucaoRepositoryInterface
{
    public const CHAVE_FILA = 'execucao:fila';

    /** ZADD NX: 1 se entrou, 0 se a OS já estava na fila. */
    private const LUA_ENFILEIRAR = <<<'LUA'
return redis.call('ZADD', KEYS[1], 'NX', ARGV[1], ARGV[2])
LUA;

    /** Devolve {-1} mecânico ocupado, {0} fila vazia ou {1, osId, score}. */
    private const LUA_PROXIMA = <<<'LUA'
if redis.call('EXISTS', KEYS[2]) == 1 then
    return {-1}
end
local item = redis.call('ZPOPMIN', KEYS[1])
if #item == 0 then
    return {0}
end
redis.call('SET', KEYS[2], item[1])
return {1, item[1], item[2]}
LUA;

    private const LUA_DEVOLVER = <<<'LUA'
redis.call('DEL', KEYS[2])
return redis.call('ZADD', KEYS[1], ARGV[1], ARGV[2])
LUA;

    private const LUA_CONCLUIR = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    private const LUA_LISTAR = <<<'LUA'
return redis.call('ZRANGE', KEYS[1], 0, -1, 'WITHSCORES')
LUA;

    public function enfileirar(int $osId, \DateTimeImmutable $em): ItemFila
    {
        $entrou = Redis::eval(self::LUA_ENFILEIRAR, 1, self::CHAVE_FILA, self::paraMs($em), $osId);

        if ((int) $entrou === 0) {
            throw new ConflitoException("A OS #{$osId} já está na fila de execução.");
        }

        return new ItemFila($osId, $em);
    }

    public function proxima(int $mecanicoId): ?ItemFila
    {
        $r = Redis::eval(self::LUA_PROXIMA, 2, self::CHAVE_FILA, self::chaveMecanico($mecanicoId));

        $codigo = (int) $r[0];

        if ($codigo === -1) {
            $osAtual = $this->osEmExecucao($mecanicoId);

            throw new ConflitoException("O mecânico #{$mecanicoId} já está executando a OS #{$osAtual}.");
        }

        if ($codigo === 0) {
            return null;
        }

        return new ItemFila((int) $r[1], self::deMs((int) $r[2]));
    }

    public function devolver(ItemFila $item, int $mecanicoId): void
    {
        Redis::eval(
            self::LUA_DEVOLVER,
            2,
            self::CHAVE_FILA,
            self::chaveMecanico($mecanicoId),
            self::paraMs($item->enfileiradaEm),
            $item->osId
        );
    }

    public function listar(): array
    {
        $plano = Redis::eval(self::LUA_LISTAR, 1, self::CHAVE_FILA);
        $itens = [];

        // Resposta achatada: [osId, score, osId, score, ...]
        for ($i = 0, $n = count($plano); $i < $n; $i += 2) {
            $itens[] = new ItemFila((int) $plano[$i], self::deMs((int) $plano[$i + 1]), intdiv($i, 2) + 1);
        }

        return $itens;
    }

    public function posicao(int $osId): ?int
    {
        $rank = Redis::zrank(self::CHAVE_FILA, (string) $osId);

        return $rank === null || $rank === false ? null : ((int) $rank) + 1;
    }

    public function remover(int $osId): bool
    {
        return (int) Redis::zrem(self::CHAVE_FILA, (string) $osId) > 0;
    }

    public function osEmExecucao(int $mecanicoId): ?int
    {
        $valor = Redis::get(self::chaveMecanico($mecanicoId));

        return $valor === null || $valor === false ? null : (int) $valor;
    }

    public function concluir(int $mecanicoId, int $osId): bool
    {
        return (int) Redis::eval(self::LUA_CONCLUIR, 1, self::chaveMecanico($mecanicoId), $osId) > 0;
    }

    public static function chaveMecanico(int $mecanicoId): string
    {
        return "execucao:mecanico:{$mecanicoId}:atual";
    }

    private static function paraMs(\DateTimeImmutable $data): int
    {
        return (int) $data->format('Uv');
    }

    private static function deMs(int $ms): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('+' . ($ms % 1000) . ' milliseconds');
    }
}
