<?php

namespace Tests\Integration;

use App\Domain\Execucao\Entities\ItemFila;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Infrastructure\Persistence\Redis\RedisFilaExecucaoRepository;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Ids;
use Tests\TestCase;

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

    public function test_ordem_fifo_pela_hora_de_chegada_na_mesma_prioridade(): void
    {
        $this->enfileirar(2, 1, '14:00:02');
        $this->enfileirar(1, 1, '14:00:01');
        $this->enfileirar(3, 1, '14:00:03');

        $this->assertSame([Ids::os(1), Ids::os(2), Ids::os(3)], $this->ordem());
        $this->assertSame(1, $this->repo->posicao(Ids::os(1)));
        $this->assertSame(3, $this->repo->posicao(Ids::os(3)));
        $this->assertNull($this->repo->posicao(Ids::os(99)));
    }

    public function test_maior_prioridade_passa_na_frente_mesmo_chegando_depois(): void
    {
        $this->enfileirar(1, 1, '14:00:01');
        $this->enfileirar(2, 3, '14:00:02');
        $this->enfileirar(3, 5, '14:00:03');
        $this->enfileirar(4, 3, '14:00:04');
        $this->enfileirar(5, 1, '14:00:05');

        // 5 (urgente) → 3 (chegou antes) → 3 (chegou depois) → 1 (chegou antes) → 1
        $this->assertSame(
            [Ids::os(3), Ids::os(2), Ids::os(4), Ids::os(1), Ids::os(5)],
            $this->ordem()
        );
        $this->assertSame(Ids::os(3), $this->repo->proxima(Ids::mecanico(7))->osId);
        $this->assertSame(Ids::os(2), $this->repo->proxima(Ids::mecanico(8))->osId);
    }

    public function test_prioridade_vence_a_chegada_em_qualquer_hora(): void
    {
        // Uma OS urgente que chegou um ano depois ainda passa na frente de uma comum.
        $this->enfileirar(1, 1, '2026-01-01 00:00:00', completo: true);
        $this->enfileirar(2, 2, '2027-12-31 23:59:59', completo: true);

        $this->assertSame([Ids::os(2), Ids::os(1)], $this->ordem());
    }

    public function test_prioridade_hora_e_execucao_id_voltam_exatos_pelo_proxima(): void
    {
        $chegada = new \DateTimeImmutable('2026-10-27 14:00:00.123', new \DateTimeZone('UTC'));
        $this->repo->enfileirar(Ids::os(1), 4, 'exec-1', $chegada);

        $item = $this->repo->proxima(Ids::mecanico(7));

        $this->assertSame(4, $item->prioridade);
        $this->assertSame('exec-1', $item->execucaoId);
        $this->assertSame('2026-10-27T14:00:00.123Z', $item->toArray()['enfileiradaEm']);
    }

    public function test_listar_devolve_prioridade_execucao_id_e_posicao(): void
    {
        $this->repo->enfileirar(Ids::os(1), 2, 'exec-1', new \DateTimeImmutable('2026-10-27 14:00:01'));
        $this->repo->enfileirar(Ids::os(2), 5, 'exec-2', new \DateTimeImmutable('2026-10-27 14:00:02'));

        [$primeiro, $segundo] = $this->repo->listar();

        $this->assertSame([Ids::os(2), 5, 'exec-2', 1], [$primeiro->osId, $primeiro->prioridade, $primeiro->execucaoId, $primeiro->posicao]);
        $this->assertSame([Ids::os(1), 2, 'exec-1', 2], [$segundo->osId, $segundo->prioridade, $segundo->execucaoId, $segundo->posicao]);
    }

    public function test_enfileirar_duas_vezes_conflita_e_preserva_o_execucao_id_original(): void
    {
        $this->repo->enfileirar(Ids::os(1), 1, 'exec-original', new \DateTimeImmutable('2026-10-27 14:00:01'));

        try {
            $this->repo->enfileirar(Ids::os(1), 5, 'exec-outro', new \DateTimeImmutable('2026-10-27 14:00:02'));
            $this->fail('Enfileirar a mesma OS duas vezes deveria conflitar.');
        } catch (ConflitoException) {
        }

        $item = $this->repo->listar()[0];
        $this->assertSame('exec-original', $item->execucaoId);
        $this->assertSame(1, $item->prioridade);
    }

    public function test_proxima_tira_da_fila_prende_o_mecanico_e_apaga_o_execucao_id_guardado(): void
    {
        $this->enfileirar(1, 1, '14:00:01');

        $item = $this->repo->proxima(Ids::mecanico(7));

        $this->assertSame(Ids::os(1), $item->osId);
        $this->assertNull($this->repo->posicao(Ids::os(1)));
        $this->assertSame(Ids::os(1), $this->repo->osEmExecucao(Ids::mecanico(7)));
        $this->assertSame(0, Redis::exists(RedisFilaExecucaoRepository::chaveExecucao(Ids::os(1))));
    }

    public function test_mecanico_ocupado_conflita_sem_consumir_a_fila(): void
    {
        $this->enfileirar(1, 1, '14:00:01');
        $this->enfileirar(2, 1, '14:00:02');
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
        $this->enfileirar(1, 1, '14:00:01');
        $this->enfileirar(2, 1, '14:00:02');

        $a = $this->repo->proxima(Ids::mecanico(7));
        $b = $this->repo->proxima(Ids::mecanico(8));

        $this->assertNotSame($a->osId, $b->osId);
        $this->assertNull($this->repo->proxima(Ids::mecanico(9)));
    }

    public function test_concluir_so_libera_quem_esta_com_a_os(): void
    {
        $this->enfileirar(1, 1, '14:00:01');
        $this->repo->proxima(Ids::mecanico(7));

        $this->assertFalse($this->repo->concluir(Ids::mecanico(7), Ids::os(999)), 'OS errada não pode liberar o mecânico.');
        $this->assertSame(Ids::os(1), $this->repo->osEmExecucao(Ids::mecanico(7)));

        $this->assertTrue($this->repo->concluir(Ids::mecanico(7), Ids::os(1)));
        $this->assertNull($this->repo->osEmExecucao(Ids::mecanico(7)));
        $this->assertFalse($this->repo->concluir(Ids::mecanico(7), Ids::os(1)), 'Idempotente: segunda chamada não faz nada.');
    }

    public function test_devolver_restaura_posicao_prioridade_e_execucao_id_e_libera_o_mecanico(): void
    {
        $this->repo->enfileirar(Ids::os(1), 3, 'exec-1', new \DateTimeImmutable('2026-10-27 14:00:01'));
        $this->repo->enfileirar(Ids::os(2), 3, 'exec-2', new \DateTimeImmutable('2026-10-27 14:00:02'));
        $item = $this->repo->proxima(Ids::mecanico(7));

        $this->repo->devolver($item, Ids::mecanico(7));

        $this->assertSame(1, $this->repo->posicao(Ids::os(1)));
        $this->assertSame(2, $this->repo->posicao(Ids::os(2)));
        $this->assertNull($this->repo->osEmExecucao(Ids::mecanico(7)));

        $restaurado = $this->repo->listar()[0];
        $this->assertSame(3, $restaurado->prioridade);
        $this->assertSame('exec-1', $restaurado->execucaoId);
    }

    public function test_remover_apaga_da_fila_e_o_execucao_id_guardado(): void
    {
        $this->enfileirar(1, 1, '14:00:01');

        $this->assertTrue($this->repo->remover(Ids::os(1)));
        $this->assertFalse($this->repo->remover(Ids::os(1)));
        $this->assertSame(0, Redis::exists(RedisFilaExecucaoRepository::chaveExecucao(Ids::os(1))));
    }

    /** @return string[] osIds na ordem de atendimento */
    private function ordem(): array
    {
        return array_map(fn (ItemFila $i) => $i->osId, $this->repo->listar());
    }

    private function enfileirar(int $os, int $prioridade, string $hora, bool $completo = false): void
    {
        $quando = new \DateTimeImmutable($completo ? $hora : "2026-10-27 {$hora}", new \DateTimeZone('UTC'));

        $this->repo->enfileirar(Ids::os($os), $prioridade, "exec-{$os}", $quando);
    }

    private function limpar(): void
    {
        $chaves = Redis::keys('execucao:*');
        if ($chaves !== []) {
            Redis::del($chaves);
        }
    }
}
