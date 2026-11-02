<?php

namespace App\Infrastructure\Persistence\Redis;

use App\Domain\Execucao\Entities\ItemFila;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;
use Illuminate\Support\Facades\Redis;

/**
 * Fila de execução no Redis:
 *
 *   execucao:fila                  Sorted Set  membro = osId, score = prioridade e chegada (abaixo)
 *   execucao:os:{osId}:execucao    String      execucaoId, enquanto a OS espera na fila
 *   execucao:mecanico:{id}:atual   String      osId que o mecânico está executando
 *
 * Score = (PRIORIDADE_MAXIMA - prioridade) * PASSO + chegada em ms. O Redis tira
 * o menor score primeiro, então a maior prioridade sai antes e, dentro da mesma
 * prioridade, a que chegou antes. PASSO tem 14 dígitos e a chegada em ms tem 13
 * (até o ano 2286), então uma não invade a outra; o maior score (4e13) cabe com
 * folga na parte inteira exata de um double (9e15).
 *
 * Toda operação que toca mais de uma chave é um script Lua, executado pelo Redis
 * sem intercalar outros comandos. A trava do mecânico não tem TTL: um reparo pode
 * durar dias, e ela só cai em concluir().
 */
class RedisFilaExecucaoRepository implements FilaExecucaoRepositoryInterface
{
    public const CHAVE_FILA = 'execucao:fila';

    private const PREFIXO_EXECUCAO = 'execucao:os:';
    private const SUFIXO_EXECUCAO = ':execucao';

    private const PASSO_PRIORIDADE = 10_000_000_000_000;

    /** ZADD NX: 1 se entrou, 0 se a OS já estava na fila. O execucaoId só é gravado se entrou. */
    private const LUA_ENFILEIRAR = <<<'LUA'
local entrou = redis.call('ZADD', KEYS[1], 'NX', ARGV[1], ARGV[2])
if entrou == 1 then
    redis.call('SET', KEYS[2], ARGV[3])
end
return entrou
LUA;

    /** Devolve {-1} mecânico ocupado, {0} fila vazia ou {1, osId, score, execucaoId}. */
    private const LUA_PROXIMA = <<<'LUA'
if redis.call('EXISTS', KEYS[2]) == 1 then
    return {-1}
end
local item = redis.call('ZPOPMIN', KEYS[1])
if #item == 0 then
    return {0}
end
redis.call('SET', KEYS[2], item[1])
local chave = ARGV[1] .. item[1] .. ARGV[2]
local execucaoId = redis.call('GET', chave)
redis.call('DEL', chave)
if not execucaoId then
    execucaoId = ''
end
return {1, item[1], item[2], execucaoId}
LUA;

    private const LUA_DEVOLVER = <<<'LUA'
redis.call('DEL', KEYS[2])
redis.call('SET', KEYS[3], ARGV[3])
return redis.call('ZADD', KEYS[1], ARGV[1], ARGV[2])
LUA;

    private const LUA_REMOVER = <<<'LUA'
redis.call('DEL', KEYS[2])
return redis.call('ZREM', KEYS[1], ARGV[1])
LUA;

    private const LUA_CONCLUIR = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    /** Resposta achatada: [osId, score, execucaoId, osId, score, execucaoId, ...] */
    private const LUA_LISTAR = <<<'LUA'
local itens = redis.call('ZRANGE', KEYS[1], 0, -1, 'WITHSCORES')
local saida = {}
for i = 1, #itens, 2 do
    local execucaoId = redis.call('GET', ARGV[1] .. itens[i] .. ARGV[2])
    if not execucaoId then
        execucaoId = ''
    end
    table.insert(saida, itens[i])
    table.insert(saida, itens[i + 1])
    table.insert(saida, execucaoId)
end
return saida
LUA;

    public function enfileirar(string $osId, int $prioridade, string $execucaoId, \DateTimeImmutable $em): ItemFila
    {
        $entrou = Redis::eval(
            self::LUA_ENFILEIRAR,
            2,
            self::CHAVE_FILA,
            self::chaveExecucao($osId),
            self::score($prioridade, $em),
            $osId,
            $execucaoId
        );

        if ((int) $entrou === 0) {
            throw new ConflitoException("A OS {$osId} já está na fila de execução.");
        }

        return new ItemFila($osId, $em, $prioridade, $execucaoId);
    }

    public function proxima(string $mecanicoId): ?ItemFila
    {
        $r = Redis::eval(
            self::LUA_PROXIMA,
            2,
            self::CHAVE_FILA,
            self::chaveMecanico($mecanicoId),
            self::PREFIXO_EXECUCAO,
            self::SUFIXO_EXECUCAO
        );

        $codigo = (int) $r[0];

        if ($codigo === -1) {
            $osAtual = $this->osEmExecucao($mecanicoId);

            throw new ConflitoException("O mecânico {$mecanicoId} já está executando a OS {$osAtual}.");
        }

        if ($codigo === 0) {
            return null;
        }

        return self::item((string) $r[1], $r[2], (string) ($r[3] ?? ''));
    }

    public function devolver(ItemFila $item, string $mecanicoId): void
    {
        Redis::eval(
            self::LUA_DEVOLVER,
            3,
            self::CHAVE_FILA,
            self::chaveMecanico($mecanicoId),
            self::chaveExecucao($item->osId),
            self::score($item->prioridade, $item->enfileiradaEm),
            $item->osId,
            $item->execucaoId ?? ''
        );
    }

    public function listar(): array
    {
        $plano = Redis::eval(self::LUA_LISTAR, 1, self::CHAVE_FILA, self::PREFIXO_EXECUCAO, self::SUFIXO_EXECUCAO);
        $itens = [];

        for ($i = 0, $n = count($plano); $i < $n; $i += 3) {
            $itens[] = self::item((string) $plano[$i], $plano[$i + 1], (string) $plano[$i + 2])
                ->comPosicao(intdiv($i, 3) + 1);
        }

        return $itens;
    }

    public function posicao(string $osId): ?int
    {
        $rank = Redis::zrank(self::CHAVE_FILA, $osId);

        return $rank === null || $rank === false ? null : ((int) $rank) + 1;
    }

    public function remover(string $osId): bool
    {
        return (int) Redis::eval(self::LUA_REMOVER, 2, self::CHAVE_FILA, self::chaveExecucao($osId), $osId) > 0;
    }

    public function osEmExecucao(string $mecanicoId): ?string
    {
        $valor = Redis::get(self::chaveMecanico($mecanicoId));

        return $valor === null || $valor === false ? null : (string) $valor;
    }

    public function concluir(string $mecanicoId, string $osId): bool
    {
        return (int) Redis::eval(self::LUA_CONCLUIR, 1, self::chaveMecanico($mecanicoId), $osId) > 0;
    }

    public static function chaveMecanico(string $mecanicoId): string
    {
        return "execucao:mecanico:{$mecanicoId}:atual";
    }

    public static function chaveExecucao(string $osId): string
    {
        return self::PREFIXO_EXECUCAO . $osId . self::SUFIXO_EXECUCAO;
    }

    private static function score(int $prioridade, \DateTimeImmutable $chegada): int
    {
        return (ItemFila::PRIORIDADE_MAXIMA - $prioridade) * self::PASSO_PRIORIDADE + (int) $chegada->format('Uv');
    }

    /** Desfaz o score em prioridade e hora de chegada. */
    private static function item(string $osId, string|int|float $score, string $execucaoId): ItemFila
    {
        $score = (int) round((float) $score);
        $chegadaMs = $score % self::PASSO_PRIORIDADE;
        $prioridade = ItemFila::PRIORIDADE_MAXIMA - intdiv($score, self::PASSO_PRIORIDADE);

        $chegada = (new \DateTimeImmutable('@' . intdiv($chegadaMs, 1000)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('+' . ($chegadaMs % 1000) . ' milliseconds');

        return new ItemFila($osId, $chegada, $prioridade, $execucaoId === '' ? null : $execucaoId);
    }
}
