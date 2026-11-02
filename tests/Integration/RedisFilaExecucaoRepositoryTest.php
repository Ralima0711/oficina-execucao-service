<?php

namespace Tests\Integration;

use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Infrastructure\Persistence\Redis\RedisFilaExecucaoRepository;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Tests\Support\Ids;

/**
 * Contra um Redis de verdade (db 15, só chaves `execucao:*`). Roda dentro do
 * compose: `docker compose exec app vendor/bin/phpunit --testsuite Integration`.
 * Sem Redis acessível, os testes são pulados.
 */
class RedisFilaExecucaoRepositoryTest extends TestCase
{
    private RedisFilaExecucaoRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        // Sempre o db 15, qualquer que seja o REDIS_DB do ambiente.
        config(['database.redis.default.database' => '15']);

        try {
            Redis::ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis indisponível.');
        }

        $this->limpar();
        $this->repo = new RedisFilaExecucaoRepository();
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            $this->limpar();
        }
        parent::tearDown();
    }

    public function test_usa_o_banco_de_testes(): void
    {
        $this->assertSame('15', (string) config('database.redis.default.database'));
    }

    public function test_ordem_fifo_pela_hora_de_chegada(): void
    {
        $this->repo->enfileirar(Ids::os(2), new \DateTimeImmutable('2026-10-27 14:00:02'));
        $this->repo->enfileirar(Ids::os(1), new \DateTimeImmutable('2026-10-27 14:00:01'));
        $this->repo->enfileirar(Ids::os(3), new \DateTimeImmutable('2026-10-27 14:00:03'));

        $this->assertSame([Ids::os(1), Ids::os(2), Ids::os(3)], array_map(fn ($i) => $i->osId, $this->repo->listar()));
        $this->assertSame(1, $this->repo->posicao(Ids::os(1)));
        $this->assertSame(3, $this->repo->posicao(Ids::os(3)));
        $this->assertNull($this->repo->posicao(Ids::os(99)));
    }

    public function test_a_hora_de_chegada_volta_com_precisao_de_milissegundos(): void
    {
        $chegada = new \DateTimeImmutable('2026-10-27 14:00:00.123', new \DateTimeZone('UTC'));
        $this->repo->enfileirar(Ids::os(1), $chegada);

        $item = $this->repo->proxima(Ids::mecanico(7));

        $this->assertSame('2026-10-27T14:00:00.123Z', $item->toArray()['enfileiradaEm']);
    }

    public function test_enfileirar_duas_vezes_conflita(): void
    {
        $this->repo->enfileirar(Ids::os(1), new \DateTimeImmutable());

        $this->expectException(ConflitoException::class);
        $this->repo->enfileirar(Ids::os(1), new \DateTimeImmutable());
    }

    public function test_proxima_tira_da_fila_e_prende_o_mecanico_na_os(): void
    {
        $this->repo->enfileirar(Ids::os(1), new \DateTimeImmutable());

        $item = $this->repo->proxima(Ids::mecanico(7));

        $this->assertSame(Ids::os(1), $item->osId);
        $this->assertNull($this->repo->posicao(Ids::os(1)));
        $this->assertSame(Ids::os(1), $this->repo->osEmExecucao(Ids::mecanico(7)));
    }

    public function test_mecanico_ocupado_conflita_sem_consumir_a_fila(): void
    {
        $this->repo->enfileirar(Ids::os(1), new \DateTimeImmutable('2026-10-27 14:00:01'));
        $this->repo->enfileirar(Ids::os(2), new \DateTimeImmutable('2026-10-27 14:00:02'));
        $this->repo->proxima(Ids::mecanico(7));

        try {
            $this->repo->proxima(Ids::mecanico(7));
            $this->fail('Mecânico ocupado deveria conflitar.');
        } catch (ConflitoException) {
        }

        $this->assertSame(1, $this->repo->posicao(Ids::os(2)));
    }

    public function test_fila_vazia_devolve_null_e_nao_deixa_trava(): void
    {
        $this->assertNull($this->repo->proxima(Ids::mecanico(7)));
        $this->assertNull($this->repo->osEmExecucao(Ids::mecanico(7)));
    }

    public function test_dois_mecanicos_nunca_recebem_a_mesma_os(): void
    {
        $this->repo->enfileirar(Ids::os(1), new \DateTimeImmutable('2026-10-27 14:00:01'));
        $this->repo->enfileirar(Ids::os(2), new \DateTimeImmutable('2026-10-27 14:00:02'));

        $a = $this->repo->proxima(Ids::mecanico(7));
        $b = $this->repo->proxima(Ids::mecanico(8));

        $this->assertNotSame($a->osId, $b->osId);
        $this->assertNull($this->repo->proxima(Ids::mecanico(9)));
    }

    public function test_concluir_so_libera_quem_esta_com_a_os(): void
    {
        $this->repo->enfileirar(Ids::os(1), new \DateTimeImmutable());
        $this->repo->proxima(Ids::mecanico(7));

        $this->assertFalse($this->repo->concluir(Ids::mecanico(7), Ids::os(999)), 'OS errada não pode liberar o mecânico.');
        $this->assertSame(Ids::os(1), $this->repo->osEmExecucao(Ids::mecanico(7)));

        $this->assertTrue($this->repo->concluir(Ids::mecanico(7), Ids::os(1)));
        $this->assertNull($this->repo->osEmExecucao(Ids::mecanico(7)));
        $this->assertFalse($this->repo->concluir(Ids::mecanico(7), Ids::os(1)), 'Idempotente: segunda chamada não faz nada.');
    }

    public function test_devolver_restaura_a_posicao_original_e_libera_o_mecanico(): void
    {
        $this->repo->enfileirar(Ids::os(1), new \DateTimeImmutable('2026-10-27 14:00:01'));
        $this->repo->enfileirar(Ids::os(2), new \DateTimeImmutable('2026-10-27 14:00:02'));
        $item = $this->repo->proxima(Ids::mecanico(7));

        $this->repo->devolver($item, Ids::mecanico(7));

        $this->assertSame(1, $this->repo->posicao(Ids::os(1)));
        $this->assertSame(2, $this->repo->posicao(Ids::os(2)));
        $this->assertNull($this->repo->osEmExecucao(Ids::mecanico(7)));
    }

    public function test_remover(): void
    {
        $this->repo->enfileirar(Ids::os(1), new \DateTimeImmutable());

        $this->assertTrue($this->repo->remover(Ids::os(1)));
        $this->assertFalse($this->repo->remover(Ids::os(1)));
    }

    private function limpar(): void
    {
        $chaves = Redis::keys('execucao:*');
        if ($chaves !== []) {
            Redis::del($chaves);
        }
    }
}
