<?php

namespace Tests\Feature;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\PublicadorDeEventosInterface;
use App\Application\Services\EnviarParaExecucaoHandler;
use App\Domain\Execucao\Repositories\MensagemProcessadaRepositoryInterface;
use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;
use App\Domain\Execucao\Repositories\TimelineRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\FilaEmMemoria;
use Tests\Support\TimelineEmMemoria;
use Tests\TestCase;
use Tests\Support\Ids;
use Tests\Support\MensagensProcessadasEmMemoria;
use Tests\Support\PublicadorEmMemoria;

/**
 * API da Execução de ponta a ponta (HTTP → serviços → repositórios) com os
 * repositórios em memória. Os repositórios reais são testados em tests/Integration.
 */
class ExecucaoApiTest extends TestCase
{
    private PublicadorEmMemoria $publicador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(FilaExecucaoRepositoryInterface::class, new FilaEmMemoria());
        $this->app->instance(TimelineRepositoryInterface::class, new TimelineEmMemoria());
        $this->app->instance(PublicadorDeEventosInterface::class, $this->publicador = new PublicadorEmMemoria());
        $this->app->instance(MensagemProcessadaRepositoryInterface::class, new MensagensProcessadasEmMemoria());

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
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])->assertCreated()->assertJsonPath('posicao', 1);
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(11)])->assertCreated()->assertJsonPath('posicao', 2);

        $this->getJson('/api/execucao/fila')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.osId', Ids::os(10))
            ->assertJsonPath('1.osId', Ids::os(11));
    }

    public function test_nao_enfileira_a_mesma_os_duas_vezes(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])->assertCreated();

        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A OS ' . Ids::os(10) . ' já está na fila de execução.');
    }

    public function test_valida_o_corpo_ao_enfileirar(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('osId');
        $this->postJson('/api/execucao/fila', [])->assertStatus(422);
        $this->postJson('/api/execucao/fila', ['osId' => 123])->assertStatus(422)->assertJsonValidationErrors('osId');
    }

    public function test_ids_que_nao_sao_uuid_sao_recusados(): void
    {
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => 7])->assertStatus(422)->assertJsonValidationErrors('mecanicoId');
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/diagnostico', ['mecanicoId' => 'joao'])->assertStatus(422);
        $this->getJson('/api/execucao/os/10/timeline')->assertStatus(404);
        $this->deleteJson('/api/execucao/fila/10')->assertStatus(404);
    }

    public function test_mecanico_puxa_a_proxima_e_a_timeline_registra_o_tempo_de_espera(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])->assertCreated();
        CarbonImmutable::setTestNow('2026-10-27 14:10:00');

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])
            ->assertOk()
            ->assertJsonPath('osId', Ids::os(10))
            ->assertJsonPath('tipo', 'EXECUCAO_INICIADA')
            ->assertJsonPath('statusAnterior', 'APROVADA')
            ->assertJsonPath('statusNovo', 'EM_EXECUCAO')
            ->assertJsonPath('mecanicoId', Ids::mecanico(7))
            ->assertJsonPath('duracaoSegundos', 600);

        $this->getJson('/api/execucao/fila')->assertOk()->assertJsonCount(0);
    }

    public function test_fila_e_fifo_entre_mecanicos(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)]);
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(11)]);

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(1)])->assertJsonPath('osId', Ids::os(10));
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(2)])->assertJsonPath('osId', Ids::os(11));
    }

    public function test_mecanico_ocupado_nao_puxa_outra_os(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)]);
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(11)]);
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])->assertOk();

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])
            ->assertStatus(409)
            ->assertJsonPath('message', 'O mecânico ' . Ids::mecanico(7) . ' já está executando a OS ' . Ids::os(10) . '.');

        // A OS 11 continua na fila: a tentativa recusada não consumiu nada.
        $this->getJson('/api/execucao/fila')->assertJsonCount(1)->assertJsonPath('0.osId', Ids::os(11));
    }

    public function test_fila_vazia_devolve_404(): void
    {
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])
            ->assertStatus(404)
            ->assertJsonPath('message', 'A fila de execução está vazia.');
    }

    public function test_remove_da_fila_e_404_quando_nao_esta_la(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)]);

        $this->deleteJson('/api/execucao/fila/' . Ids::os(10))->assertNoContent();
        $this->deleteJson('/api/execucao/fila/' . Ids::os(10))->assertStatus(404);
    }

    public function test_os_em_execucao_ou_finalizada_nao_volta_para_a_fila(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)]);
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)]);

        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])->assertStatus(409);

        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(7)])->assertOk();
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])->assertStatus(409);
    }

    // ── Timeline ──────────────────────────────────────────────────

    public function test_fluxo_completo_diagnostico_notas_execucao_e_finalizacao(): void
    {
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/diagnostico', ['mecanicoId' => Ids::mecanico(7), 'observacao' => 'barulho na frente'])
            ->assertCreated()
            ->assertJsonPath('tipo', 'DIAGNOSTICO_INICIADO')
            ->assertJsonPath('statusNovo', 'EM_DIAGNOSTICO');

        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/notas', ['mecanicoId' => Ids::mecanico(7), 'texto' => 'pastilhas no limite'])
            ->assertCreated()
            ->assertJsonPath('tipo', 'NOTA');

        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)]);
        CarbonImmutable::setTestNow('2026-10-27 14:05:00');
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])->assertOk();

        CarbonImmutable::setTestNow('2026-10-27 15:35:00');
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(7), 'observacao' => 'trocadas'])
            ->assertOk()
            ->assertJsonPath('statusNovo', 'FINALIZADA')
            ->assertJsonPath('duracaoSegundos', 5400);

        $this->getJson('/api/execucao/os/' . Ids::os(10) . '/timeline')
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.tipo', 'DIAGNOSTICO_INICIADO')
            ->assertJsonPath('1.tipo', 'NOTA')
            ->assertJsonPath('2.tipo', 'EXECUCAO_INICIADA')
            ->assertJsonPath('3.tipo', 'EXECUCAO_FINALIZADA');
    }

    public function test_finalizar_libera_o_mecanico_para_a_proxima_os(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)]);
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(11)]);
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])->assertOk();
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(7)])->assertOk();

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])->assertOk()->assertJsonPath('osId', Ids::os(11));
    }

    public function test_timeline_de_os_sem_eventos_devolve_404(): void
    {
        $this->getJson('/api/execucao/os/' . Ids::os(999) . '/timeline')->assertStatus(404);
    }

    public function test_so_inicia_diagnostico_de_os_recebida(): void
    {
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/diagnostico', ['mecanicoId' => Ids::mecanico(7)])->assertCreated();

        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/diagnostico', ['mecanicoId' => Ids::mecanico(7)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Somente ordens RECEBIDAS podem iniciar diagnóstico (status atual: EM_DIAGNOSTICO).');
    }

    public function test_nota_exige_diagnostico_ou_execucao_em_andamento(): void
    {
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/notas', ['mecanicoId' => Ids::mecanico(7), 'texto' => 'oi'])->assertStatus(422);

        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/diagnostico', ['mecanicoId' => Ids::mecanico(7)]);
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/notas', ['mecanicoId' => Ids::mecanico(7), 'texto' => 'oi'])->assertCreated();
    }

    public function test_outro_mecanico_nao_anota_nem_finaliza_a_os(): void
    {
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/diagnostico', ['mecanicoId' => Ids::mecanico(7)]);

        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/notas', ['mecanicoId' => Ids::mecanico(9), 'texto' => 'oi'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A OS ' . Ids::os(10) . ' está com o mecânico ' . Ids::mecanico(7) . '.');

        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)]);
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)]);

        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(9)])->assertStatus(409);
    }

    public function test_so_finaliza_os_em_execucao(): void
    {
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(7)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Somente ordens EM_EXECUCAO podem ser finalizadas (status atual: RECEBIDA).');
    }

    public function test_valida_mecanico_e_texto(): void
    {
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/diagnostico', [])->assertStatus(422)->assertJsonValidationErrors('mecanicoId');
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/notas', ['mecanicoId' => Ids::mecanico(7)])->assertStatus(422)->assertJsonValidationErrors('texto');
    }

    public function test_metricas_de_tempo_medio_por_status(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)]);
        CarbonImmutable::setTestNow('2026-10-27 14:10:00');
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)]);
        CarbonImmutable::setTestNow('2026-10-27 15:10:00');
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(7)]);

        $this->getJson('/api/execucao/metricas/tempo-medio')
            ->assertOk()
            ->assertJson([
                ['status' => 'APROVADA', 'tempoMedioSegundos' => 600, 'amostras' => 1],
                ['status' => 'EM_EXECUCAO', 'tempoMedioSegundos' => 3600, 'amostras' => 1],
            ]);
    }

    // ── Prioridade e execucaoId ───────────────────────────────────

    public function test_maior_prioridade_passa_na_frente_e_na_mesma_prioridade_vale_a_chegada(): void
    {
        $chegadas = [[10, 1], [11, 3], [12, 1], [13, 3], [14, 5]];
        foreach ($chegadas as $i => [$os, $prioridade]) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-27 14:00:00')->addSeconds($i));
            $this->postJson('/api/execucao/fila', ['osId' => Ids::os($os), 'prioridade' => $prioridade])->assertCreated();
        }

        $this->getJson('/api/execucao/fila')
            ->assertOk()
            ->assertJsonPath('0.osId', Ids::os(14))
            ->assertJsonPath('1.osId', Ids::os(11))
            ->assertJsonPath('2.osId', Ids::os(13))
            ->assertJsonPath('3.osId', Ids::os(10))
            ->assertJsonPath('4.osId', Ids::os(12))
            ->assertJsonPath('0.prioridade', 5)
            ->assertJsonPath('4.posicao', 5);

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(1)])->assertJsonPath('osId', Ids::os(14));
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(2)])->assertJsonPath('osId', Ids::os(11));
    }

    public function test_prioridade_padrao_e_1_e_fora_da_faixa_e_recusada(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])->assertCreated()->assertJsonPath('prioridade', 1);

        foreach ([0, 6, -1, 'alta'] as $invalida) {
            $this->postJson('/api/execucao/fila', ['osId' => Ids::os(11), 'prioridade' => $invalida])
                ->assertStatus(422)
                ->assertJsonValidationErrors('prioridade');
        }
    }

    public function test_execucao_id_nasce_ao_enfileirar_e_acompanha_a_os_ate_a_finalizacao(): void
    {
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/diagnostico', ['mecanicoId' => Ids::mecanico(7)]);

        $execucaoId = $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])
            ->assertCreated()
            ->json('execucaoId');

        $this->assertTrue(Str::isUuid($execucaoId));

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])
            ->assertJsonPath('execucaoId', $execucaoId);
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(7)])
            ->assertJsonPath('execucaoId', $execucaoId);

        $timeline = $this->getJson('/api/execucao/os/' . Ids::os(10) . '/timeline')->json();

        $this->assertNull($timeline[0]['execucaoId'], 'O diagnóstico acontece antes de a OS entrar na fila.');
        $this->assertSame($execucaoId, $timeline[1]['execucaoId']);
        $this->assertSame($execucaoId, $timeline[2]['execucaoId']);
    }

    public function test_cada_os_recebe_o_seu_execucao_id(): void
    {
        $a = $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])->json('execucaoId');
        $b = $this->postJson('/api/execucao/fila', ['osId' => Ids::os(11)])->json('execucaoId');

        $this->assertNotSame($a, $b);
    }

    // ── Mensageria: OS que veio de uma saga ───────────────────────

    public function test_os_vinda_de_comando_publica_iniciada_e_concluida_de_volta_na_mesma_saga(): void
    {
        $comando = $this->comandoDeSaga(Ids::os(10));
        $this->app->make(EnviarParaExecucaoHandler::class)->handle($comando);

        $execucaoId = $this->getJson('/api/execucao/fila')->json('0.execucaoId');

        $this->assertSame([], $this->publicador->publicadas, 'Enfileirar não publica nada: o evento sai quando o mecânico começa.');

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])->assertOk();
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(7)])->assertOk();

        $this->assertSame(['execucao.iniciada', 'execucao.concluida'], $this->publicador->routingKeys());

        foreach ($this->publicador->publicadas as ['envelope' => $envelope]) {
            $this->assertSame($comando->sagaId, $envelope->sagaId);
            $this->assertSame($comando->messageId, $envelope->causationId);
            $this->assertSame(Ids::os(10), $envelope->body['osId']);
            $this->assertSame($execucaoId, $envelope->body['execucaoId']);
            $this->assertSame(Ids::mecanico(7), $envelope->body['mecanicoId']);
        }
    }

    public function test_a_timeline_de_uma_os_de_saga_aponta_para_o_trace_do_comando(): void
    {
        $comando = $this->comandoDeSaga(Ids::os(10));
        $this->app->make(EnviarParaExecucaoHandler::class)->handle($comando);

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])
            ->assertOk()
            ->assertJsonPath('sagaId', $comando->sagaId)
            ->assertJsonPath('traceId', '0af7651916cd43dd8448eb211c80319c');
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(7)])
            ->assertJsonPath('sagaId', $comando->sagaId)
            ->assertJsonPath('traceId', '0af7651916cd43dd8448eb211c80319c');
    }

    public function test_os_enfileirada_pela_api_nao_publica_evento_porque_nao_tem_saga(): void
    {
        $this->postJson('/api/execucao/fila', ['osId' => Ids::os(10)])->assertCreated();
        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])->assertOk();
        $this->postJson('/api/execucao/os/' . Ids::os(10) . '/finalizar', ['mecanicoId' => Ids::mecanico(7)])->assertOk();

        $this->assertSame([], $this->publicador->publicadas);
    }

    public function test_falha_do_broker_devolve_503_mas_a_transicao_ja_esta_gravada(): void
    {
        $this->app->make(EnviarParaExecucaoHandler::class)->handle($this->comandoDeSaga(Ids::os(10)));
        $this->publicador->falhar = true;

        $this->postJson('/api/execucao/fila/proxima', ['mecanicoId' => Ids::mecanico(7)])
            ->assertStatus(503)
            ->assertJsonPath('message', 'A transição foi gravada, mas o evento não foi publicado no broker.');

        $this->getJson('/api/execucao/os/' . Ids::os(10) . '/timeline')
            ->assertOk()
            ->assertJsonPath('0.tipo', 'EXECUCAO_INICIADA');
    }

    private function comandoDeSaga(string $osId): Envelope
    {
        return Envelope::novo(
            'EnviarParaExecucao',
            '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f',
            null,
            ['osId' => $osId, 'orcamentoId' => Ids::os(99)],
            '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'
        );
    }

    public function test_rota_com_os_id_nao_numerico_nao_existe(): void
    {
        $this->getJson('/api/execucao/os/abc/timeline')->assertStatus(404);
    }
}
