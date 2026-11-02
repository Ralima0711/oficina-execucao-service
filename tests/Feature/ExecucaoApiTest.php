<?php

namespace Tests\Feature;

use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;
use App\Domain\Execucao\Repositories\TimelineRepositoryInterface;
use Carbon\CarbonImmutable;
use Tests\Support\FilaEmMemoria;
use Tests\Support\TimelineEmMemoria;
use Tests\TestCase;

/**
 * API da Execução de ponta a ponta (HTTP → serviços → repositórios) com os
 * repositórios em memória. Os repositórios reais são testados em tests/Integration.
 */
class ExecucaoApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(FilaExecucaoRepositoryInterface::class, new FilaEmMemoria());
        $this->app->instance(TimelineRepositoryInterface::class, new TimelineEmMemoria());

        CarbonImmutable::setTestNow('2026-10-27 14:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── Fila ──────────────────────────────────────────────────────

    public function test_enfileira_e_lista_em_ordem_de_chegada(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 10])->assertCreated()->assertJsonPath('posicao', 1);
        $this->postJson('/api/execucao/fila', ['osId' => 11])->assertCreated()->assertJsonPath('posicao', 2);

        $this->getJson('/api/execucao/fila')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.osId', 10)
            ->assertJsonPath('1.osId', 11);
    }

    public function test_nao_enfileira_a_mesma_os_duas_vezes(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 10])->assertCreated();

        $this->postJson('/api/execucao/fila', ['osId' => 10])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A OS #10 já está na fila de execução.');
    }

    public function test_valida_o_corpo_ao_enfileirar(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('osId');
        $this->postJson('/api/execucao/fila', [])->assertStatus(422);
        $this->postJson('/api/execucao/fila', ['osId' => 0])->assertStatus(422);
    }

    public function test_mecanico_puxa_a_proxima_e_a_timeline_registra_o_tempo_de_espera(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 10])->assertCreated();
        CarbonImmutable::setTestNow('2026-10-27 14:10:00');

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7])
            ->assertOk()
            ->assertJsonPath('osId', 10)
            ->assertJsonPath('tipo', 'EXECUCAO_INICIADA')
            ->assertJsonPath('statusAnterior', 'APROVADA')
            ->assertJsonPath('statusNovo', 'EM_EXECUCAO')
            ->assertJsonPath('mecanicoId', 7)
            ->assertJsonPath('duracaoSegundos', 600);

        $this->getJson('/api/execucao/fila')->assertOk()->assertJsonCount(0);
    }

    public function test_fila_e_fifo_entre_mecanicos(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 10]);
        $this->postJson('/api/execucao/fila', ['osId' => 11]);

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 1])->assertJsonPath('osId', 10);
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 2])->assertJsonPath('osId', 11);
    }

    public function test_mecanico_ocupado_nao_puxa_outra_os(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 10]);
        $this->postJson('/api/execucao/fila', ['osId' => 11]);
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7])->assertOk();

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7])
            ->assertStatus(409)
            ->assertJsonPath('message', 'O mecânico #7 já está executando a OS #10.');

        // A OS 11 continua na fila: a tentativa recusada não consumiu nada.
        $this->getJson('/api/execucao/fila')->assertJsonCount(1)->assertJsonPath('0.osId', 11);
    }

    public function test_fila_vazia_devolve_404(): void
    {
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7])
            ->assertStatus(404)
            ->assertJsonPath('message', 'A fila de execução está vazia.');
    }

    public function test_remove_da_fila_e_404_quando_nao_esta_la(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 10]);

        $this->deleteJson('/api/execucao/fila/10')->assertNoContent();
        $this->deleteJson('/api/execucao/fila/10')->assertStatus(404);
    }

    public function test_os_em_execucao_ou_finalizada_nao_volta_para_a_fila(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 10]);
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7]);

        $this->postJson('/api/execucao/fila', ['osId' => 10])->assertStatus(409);

        $this->postJson('/api/execucao/os/10/finalizar', ['mecanicoId' => 7])->assertOk();
        $this->postJson('/api/execucao/fila', ['osId' => 10])->assertStatus(409);
    }

    // ── Timeline ──────────────────────────────────────────────────

    public function test_fluxo_completo_diagnostico_notas_execucao_e_finalizacao(): void
    {
        $this->postJson('/api/execucao/os/10/diagnostico', ['mecanicoId' => 7, 'observacao' => 'barulho na frente'])
            ->assertCreated()
            ->assertJsonPath('tipo', 'DIAGNOSTICO_INICIADO')
            ->assertJsonPath('statusNovo', 'EM_DIAGNOSTICO');

        $this->postJson('/api/execucao/os/10/notas', ['mecanicoId' => 7, 'texto' => 'pastilhas no limite'])
            ->assertCreated()
            ->assertJsonPath('tipo', 'NOTA');

        $this->postJson('/api/execucao/fila', ['osId' => 10]);
        CarbonImmutable::setTestNow('2026-10-27 14:05:00');
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7])->assertOk();

        CarbonImmutable::setTestNow('2026-10-27 15:35:00');
        $this->postJson('/api/execucao/os/10/finalizar', ['mecanicoId' => 7, 'observacao' => 'trocadas'])
            ->assertOk()
            ->assertJsonPath('statusNovo', 'FINALIZADA')
            ->assertJsonPath('duracaoSegundos', 5400);

        $this->getJson('/api/execucao/os/10/timeline')
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.tipo', 'DIAGNOSTICO_INICIADO')
            ->assertJsonPath('1.tipo', 'NOTA')
            ->assertJsonPath('2.tipo', 'EXECUCAO_INICIADA')
            ->assertJsonPath('3.tipo', 'EXECUCAO_FINALIZADA');
    }

    public function test_finalizar_libera_o_mecanico_para_a_proxima_os(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 10]);
        $this->postJson('/api/execucao/fila', ['osId' => 11]);
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7])->assertOk();
        $this->postJson('/api/execucao/os/10/finalizar', ['mecanicoId' => 7])->assertOk();

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7])->assertOk()->assertJsonPath('osId', 11);
    }

    public function test_timeline_de_os_sem_eventos_devolve_404(): void
    {
        $this->getJson('/api/execucao/os/999/timeline')->assertStatus(404);
    }

    public function test_so_inicia_diagnostico_de_os_recebida(): void
    {
        $this->postJson('/api/execucao/os/10/diagnostico', ['mecanicoId' => 7])->assertCreated();

        $this->postJson('/api/execucao/os/10/diagnostico', ['mecanicoId' => 7])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Somente ordens RECEBIDAS podem iniciar diagnóstico (status atual: EM_DIAGNOSTICO).');
    }

    public function test_nota_exige_diagnostico_ou_execucao_em_andamento(): void
    {
        $this->postJson('/api/execucao/os/10/notas', ['mecanicoId' => 7, 'texto' => 'oi'])->assertStatus(422);

        $this->postJson('/api/execucao/os/10/diagnostico', ['mecanicoId' => 7]);
        $this->postJson('/api/execucao/os/10/notas', ['mecanicoId' => 7, 'texto' => 'oi'])->assertCreated();
    }

    public function test_outro_mecanico_nao_anota_nem_finaliza_a_os(): void
    {
        $this->postJson('/api/execucao/os/10/diagnostico', ['mecanicoId' => 7]);

        $this->postJson('/api/execucao/os/10/notas', ['mecanicoId' => 9, 'texto' => 'oi'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A OS #10 está com o mecânico #7.');

        $this->postJson('/api/execucao/fila', ['osId' => 10]);
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7]);

        $this->postJson('/api/execucao/os/10/finalizar', ['mecanicoId' => 9])->assertStatus(409);
    }

    public function test_so_finaliza_os_em_execucao(): void
    {
        $this->postJson('/api/execucao/os/10/finalizar', ['mecanicoId' => 7])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Somente ordens EM_EXECUCAO podem ser finalizadas (status atual: RECEBIDA).');
    }

    public function test_valida_mecanico_e_texto(): void
    {
        $this->postJson('/api/execucao/os/10/diagnostico', [])->assertStatus(422)->assertJsonValidationErrors('mecanicoId');
        $this->postJson('/api/execucao/os/10/notas', ['mecanicoId' => 7])->assertStatus(422)->assertJsonValidationErrors('texto');
    }

    public function test_metricas_de_tempo_medio_por_status(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 10]);
        CarbonImmutable::setTestNow('2026-10-27 14:10:00');
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7]);
        CarbonImmutable::setTestNow('2026-10-27 15:10:00');
        $this->postJson('/api/execucao/os/10/finalizar', ['mecanicoId' => 7]);

        $this->getJson('/api/execucao/metricas/tempo-medio')
            ->assertOk()
            ->assertJson([
                ['status' => 'APROVADA', 'tempoMedioSegundos' => 600, 'amostras' => 1],
                ['status' => 'EM_EXECUCAO', 'tempoMedioSegundos' => 3600, 'amostras' => 1],
            ]);
    }

    public function test_rota_com_os_id_nao_numerico_nao_existe(): void
    {
        $this->getJson('/api/execucao/os/abc/timeline')->assertStatus(404);
    }
}
