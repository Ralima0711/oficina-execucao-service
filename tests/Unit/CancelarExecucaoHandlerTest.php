<?php

namespace Tests\Unit;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MensagemInvalida;
use App\Application\Services\CancelarExecucaoHandler;
use App\Application\Services\EnviarParaExecucaoHandler;
use App\Application\Services\EventosDeExecucao;
use App\Application\Services\ExecucaoService;
use App\Application\Services\FilaExecucaoService;
use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\ValueObjects\ResultadoCancelamento;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use App\Infrastructure\Messaging\MessageTracing;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\Support\FilaEmMemoria;
use Tests\Support\Ids;
use Tests\Support\MensagensProcessadasEmMemoria;
use Tests\Support\PublicadorEmMemoria;
use Tests\Support\TimelineEmMemoria;
use Tests\TestCase;

/**
 * CancelarExecucao, a compensação da saga (contrato v1.1, Parte 3b): o que o comando faz em cada
 * situação da OS na Execução, e que repetir o comando não repete o efeito.
 */
class CancelarExecucaoHandlerTest extends TestCase
{
    use UsaTracerEmMemoria;

    private const SAGA_ID = '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f';
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    private FilaEmMemoria $fila;

    private TimelineEmMemoria $timeline;

    private PublicadorEmMemoria $publicador;

    private MensagensProcessadasEmMemoria $mensagens;

    private FilaExecucaoService $filaService;

    private ExecucaoService $execucao;

    private CancelarExecucaoHandler $cancelar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usarTracerEmMemoria();

        $this->fila = new FilaEmMemoria();
        $this->timeline = new TimelineEmMemoria();
        $this->publicador = new PublicadorEmMemoria();
        $this->mensagens = new MensagensProcessadasEmMemoria();

        $eventos = new EventosDeExecucao($this->publicador);
        $this->filaService = new FilaExecucaoService($this->fila, $this->timeline, $eventos);
        $this->execucao = new ExecucaoService($this->timeline, $this->fila, $eventos);
        $this->cancelar = new CancelarExecucaoHandler($this->execucao, $this->mensagens);
    }

    protected function tearDown(): void
    {
        $this->restaurarTracer();
        parent::tearDown();
    }

    // ── 1. ainda na fila ──────────────────────────────────────────

    public function test_na_fila_sai_da_fila_e_publica_falhou_cancelada_sem_mecanico(): void
    {
        $this->enviar(Ids::os(10));
        $execucaoId = $this->fila->listar()[0]->execucaoId;

        $comando = $this->comando(Ids::os(10));
        $this->consumir($comando);

        $this->assertSame([], $this->fila->listar(), 'A OS saiu da fila.');

        $this->assertSame(['execucao.falhou'], $this->publicador->routingKeys());
        $envelope = $this->publicador->publicadas[0]['envelope'];
        $this->assertSame([
            'osId'       => Ids::os(10),
            'execucaoId' => $execucaoId,
            'motivo'     => 'cancelada',
        ], $envelope->body, 'Antes de alguém puxar não há mecanicoId nem iniciadaEm.');
        $this->assertSame(self::SAGA_ID, $envelope->sagaId);
        $this->assertSame($comando->messageId, $envelope->causationId, 'O evento responde ao CancelarExecucao.');
    }

    public function test_na_fila_a_timeline_registra_o_fim_da_execucao_e_a_os_nao_volta_para_a_fila(): void
    {
        $this->enviar(Ids::os(10));
        $execucaoId = $this->fila->listar()[0]->execucaoId;

        $this->consumir($this->comando(Ids::os(10), ['motivo' => 'pagamento_recusado']));

        $evento = $this->timeline->ultimoEventoDeEstado(Ids::os(10));
        $this->assertSame(TipoEvento::EXECUCAO_FALHOU, $evento->tipo);
        $this->assertSame(StatusExecucao::APROVADA, $evento->statusAnterior);
        $this->assertSame(StatusExecucao::FALHOU, $evento->statusNovo);
        $this->assertNull($evento->mecanicoId);
        $this->assertSame($execucaoId, $evento->execucaoId, 'O execucaoId nascido na fila identifica a falha.');
        $this->assertSame('cancelada (pagamento_recusado)', $evento->observacao);
        $this->assertSame(self::TRACE_ID, $evento->traceId);

        // FALHOU é terminal: um comando atrasado não reabre a OS (regra 7 da Parte 5).
        $this->enviar(Ids::os(10));
        $this->assertSame([], $this->fila->listar());
        $this->assertSame(['execucao.falhou'], $this->publicador->routingKeys());
    }

    public function test_o_evento_sai_no_trace_do_cancelamento_encadeado_ao_span_do_consumidor(): void
    {
        $this->enviar(Ids::os(10));

        $this->consumir($this->comando(Ids::os(10)));

        $consumidor = $this->span('execucao.cancelar-execucao process');
        $publicacao = $this->span('execucao.falhou publish');

        $this->assertSame(self::TRACE_ID, $publicacao->getTraceId());
        $this->assertSame($consumidor->getSpanId(), $publicacao->getParentSpanId());
        $this->assertSame(
            sprintf('00-%s-%s-01', self::TRACE_ID, $publicacao->getSpanId()),
            $this->publicador->publicadas[0]['envelope']->traceparent
        );
    }

    // ── 2. reparo em andamento ────────────────────────────────────

    public function test_em_andamento_encerra_o_reparo_libera_o_mecanico_e_publica_falhou(): void
    {
        $this->enviar(Ids::os(10));
        $this->filaService->proxima(Ids::mecanico(7));
        $iniciada = $this->timeline->ultimoEventoDeEstado(Ids::os(10));
        $this->publicador->publicadas = [];

        $comando = $this->comando(Ids::os(10));
        $this->consumir($comando);

        $this->assertNull($this->fila->osEmExecucao(Ids::mecanico(7)), 'O mecânico foi liberado.');

        $fim = $this->timeline->ultimoEventoDeEstado(Ids::os(10));
        $this->assertSame(TipoEvento::EXECUCAO_FALHOU, $fim->tipo);
        $this->assertSame(StatusExecucao::EM_EXECUCAO, $fim->statusAnterior);
        $this->assertSame(StatusExecucao::FALHOU, $fim->statusNovo);
        $this->assertSame(Ids::mecanico(7), $fim->mecanicoId);
        $this->assertSame($iniciada->execucaoId, $fim->execucaoId);

        $this->assertSame(['execucao.falhou'], $this->publicador->routingKeys());
        $envelope = $this->publicador->publicadas[0]['envelope'];
        $this->assertSame('cancelada', $envelope->body['motivo']);
        $this->assertSame(Ids::mecanico(7), $envelope->body['mecanicoId']);
        $this->assertSame($iniciada->execucaoId, $envelope->body['execucaoId']);
        $this->assertArrayHasKey('iniciadaEm', $envelope->body);
        $this->assertSame($comando->messageId, $envelope->causationId);
    }

    public function test_o_mecanico_liberado_ja_puxa_a_proxima_os(): void
    {
        $this->enviar(Ids::os(10));
        $this->enviar(Ids::os(11));
        $this->filaService->proxima(Ids::mecanico(7));

        $this->consumir($this->comando(Ids::os(10)));

        $proxima = $this->filaService->proxima(Ids::mecanico(7));
        $this->assertSame(Ids::os(11), $proxima->osId);
    }

    // ── 3. já concluída ───────────────────────────────────────────

    public function test_ja_concluida_nao_desfaz_nao_publica_e_registra_a_tentativa(): void
    {
        $this->enviar(Ids::os(10));
        $this->filaService->proxima(Ids::mecanico(7));
        $this->execucao->finalizar(Ids::os(10), Ids::mecanico(7));
        $this->publicador->publicadas = [];

        $this->consumir($this->comando(Ids::os(10), ['motivo' => 'pagamento_recusado']));

        $this->assertSame([], $this->publicador->publicadas, 'Reparo feito não volta atrás: nenhum execucao.falhou.');

        $ultimo = $this->timeline->ultimoEventoDeEstado(Ids::os(10));
        $this->assertSame(StatusExecucao::FINALIZADA, $ultimo->statusNovo, 'A OS continua FINALIZADA.');

        $eventos = $this->timeline->porOs(Ids::os(10));
        $tentativa = end($eventos);
        $this->assertSame(TipoEvento::NOTA, $tentativa->tipo);
        $this->assertStringContainsString('já foi concluída', $tentativa->observacao);
        $this->assertStringContainsString('pagamento_recusado', $tentativa->observacao);
        $this->assertSame(self::SAGA_ID, $tentativa->saga->sagaId);
    }

    public function test_cada_tentativa_em_os_concluida_fica_registrada(): void
    {
        $this->enviar(Ids::os(10));
        $this->filaService->proxima(Ids::mecanico(7));
        $this->execucao->finalizar(Ids::os(10), Ids::mecanico(7));

        $this->consumir($this->comando(Ids::os(10)));
        $this->consumir($this->comando(Ids::os(10)));

        $notas = array_filter($this->timeline->porOs(Ids::os(10)), fn (EventoTimeline $e) => $e->tipo === TipoEvento::NOTA);
        $this->assertCount(2, $notas);
    }

    // ── não se aplica / repetição ─────────────────────────────────

    public function test_os_que_nunca_chegou_a_fila_responde_sucesso_sem_fazer_nada(): void
    {
        $this->consumir($this->comando(Ids::os(10)));

        $this->assertSame([], $this->publicador->publicadas);
        $this->assertSame([], $this->timeline->porOs(Ids::os(10)));
        $this->assertSame(ResultadoCancelamento::NAO_SE_APLICA, $this->resultadoDe(Ids::os(10)));
    }

    public function test_mensagem_repetida_com_o_mesmo_message_id_e_descartada(): void
    {
        $this->enviar(Ids::os(10));
        $comando = $this->comando(Ids::os(10));

        $this->consumir($comando);
        $this->consumir($comando);

        $this->assertSame(['execucao.falhou'], $this->publicador->routingKeys());
    }

    public function test_outro_cancelamento_da_mesma_os_tem_efeito_so_uma_vez(): void
    {
        $this->enviar(Ids::os(10));

        $this->consumir($this->comando(Ids::os(10)));
        $this->consumir($this->comando(Ids::os(10)));

        $this->assertSame(['execucao.falhou'], $this->publicador->routingKeys());
        $this->assertCount(1, array_filter($this->timeline->porOs(Ids::os(10)), fn (EventoTimeline $e) => $e->tipo === TipoEvento::EXECUCAO_FALHOU));
        $this->assertSame(ResultadoCancelamento::JA_ENCERRADA, $this->resultadoDe(Ids::os(10)));
    }

    public function test_cancelar_nao_mexe_nas_outras_os(): void
    {
        $this->enviar(Ids::os(10));
        $this->enviar(Ids::os(11));

        $this->consumir($this->comando(Ids::os(10)));

        $this->assertSame([Ids::os(11)], array_map(fn ($i) => $i->osId, $this->fila->listar()));
    }

    // ── comando fora do contrato ──────────────────────────────────

    #[DataProvider('corposInvalidos')]
    public function test_corpo_fora_do_contrato_e_recusado_sem_marcar_a_mensagem(array $corpo, string $campo): void
    {
        $this->enviar(Ids::os(10));
        $comando = $this->comando(Ids::os(10), [], $corpo);

        try {
            $this->consumir($comando);
            $this->fail('Deveria recusar o corpo inválido.');
        } catch (MensagemInvalida $e) {
            $this->assertStringContainsString($campo, $e->getMessage());
        }

        $this->assertCount(1, $this->fila->listar(), 'Nada foi cancelado.');
        $this->assertTrue($this->mensagens->marcarSeNova($comando->messageId), 'Mensagem inválida não pode ficar registrada como tratada.');
    }

    public static function corposInvalidos(): array
    {
        return [
            'osId ausente'        => [['osId' => null], 'os id'],
            'osId não-uuid'       => [['osId' => '123'], 'os id'],
            'motivo ausente'      => [['motivo' => null], 'motivo'],
            'orcamentoId não-uuid' => [['orcamentoId' => 'x'], 'orcamento id'],
            'pagamentoId não-uuid' => [['pagamentoId' => 'x'], 'pagamento id'],
        ];
    }

    public function test_motivo_que_o_contrato_nao_lista_nao_manda_a_compensacao_para_a_dlq(): void
    {
        $this->enviar(Ids::os(10));

        $this->consumir($this->comando(Ids::os(10), ['motivo' => 'motivo_novo_da_v2']));

        $this->assertSame([], $this->fila->listar());
        $this->assertSame(['execucao.falhou'], $this->publicador->routingKeys());
    }

    // ── falha de infraestrutura ───────────────────────────────────

    public function test_falha_ao_gravar_a_timeline_devolve_a_os_a_fila_na_mesma_posicao_e_libera_o_message_id(): void
    {
        $this->enviar(Ids::os(10), 3);
        $this->enviar(Ids::os(11), 3);
        $antes = $this->fila->listar();

        $timeline = new class extends TimelineEmMemoria {
            public bool $falhar = true;

            public function registrar(EventoTimeline $evento): void
            {
                if ($this->falhar && $evento->tipo === TipoEvento::EXECUCAO_FALHOU) {
                    throw new \RuntimeException('mongo fora do ar');
                }

                parent::registrar($evento);
            }
        };
        $handler = $this->handlerCom($timeline);
        $comando = $this->comando(Ids::os(10));

        try {
            $this->consumirCom($handler, $comando);
            $this->fail('A falha deveria ser repropagada (a mensagem vai para a DLQ).');
        } catch (\RuntimeException $e) {
            $this->assertSame('mongo fora do ar', $e->getMessage());
        }

        $this->assertEquals($antes, $this->fila->listar(), 'A OS continua na fila, na mesma posição e com o mesmo execucaoId.');
        $this->assertSame([], $this->publicador->publicadas);

        $timeline->falhar = false;
        $this->consumirCom($handler, $comando);

        $this->assertSame(['execucao.falhou'], $this->publicador->routingKeys(), 'O retry da mesma mensagem não é descartado como repetição.');
    }

    public function test_falha_do_broker_ao_publicar_libera_o_message_id(): void
    {
        $this->enviar(Ids::os(10));
        $this->publicador->falhar = true;
        $comando = $this->comando(Ids::os(10));

        try {
            $this->consumir($comando);
            $this->fail('A falha do broker deveria ser repropagada.');
        } catch (\Throwable) {
        }

        $this->assertTrue($this->mensagens->marcarSeNova($comando->messageId), 'O messageId foi liberado para o retry.');
    }

    // ── apoio ─────────────────────────────────────────────────────

    private function enviar(string $osId, int $prioridade = 1): void
    {
        $envelope = Envelope::novo('EnviarParaExecucao', self::SAGA_ID, null, ['osId' => $osId, 'orcamentoId' => Ids::os(99), 'prioridade' => $prioridade], self::TRACEPARENT);

        MessageTracing::consumir(
            'execucao.enviar-para-execucao',
            ['traceparent' => self::TRACEPARENT],
            fn () => (new EnviarParaExecucaoHandler($this->filaService, new MensagensProcessadasEmMemoria()))->handle($envelope)
        );
    }

    /** @param array<string, mixed> $extra @param array<string, mixed> $troca */
    private function comando(string $osId, array $extra = [], array $troca = []): Envelope
    {
        $corpo = array_merge(
            ['osId' => $osId, 'orcamentoId' => Ids::os(99), 'motivo' => 'execucao_falhou'],
            $extra,
            $troca
        );

        return Envelope::novo('CancelarExecucao', self::SAGA_ID, null, array_filter($corpo, fn ($v) => $v !== null), self::TRACEPARENT);
    }

    private function consumir(Envelope $envelope): void
    {
        $this->consumirCom($this->cancelar, $envelope);
    }

    /** Como o consumidor faz: o tratamento roda no span CONSUMER filho do traceparent recebido. */
    private function consumirCom(CancelarExecucaoHandler $handler, Envelope $envelope): void
    {
        MessageTracing::consumir(
            'execucao.cancelar-execucao',
            ['traceparent' => $envelope->traceparent],
            fn () => $handler->handle($envelope)
        );
    }

    private function handlerCom(TimelineEmMemoria $timeline): CancelarExecucaoHandler
    {
        $execucao = new ExecucaoService($timeline, $this->fila, new EventosDeExecucao($this->publicador));

        return new CancelarExecucaoHandler($execucao, $this->mensagens);
    }

    /** O que um novo cancelamento faria agora com a OS (usa um messageId novo). */
    private function resultadoDe(string $osId): ResultadoCancelamento
    {
        $saga = new \App\Domain\Execucao\ValueObjects\ContextoSaga(self::SAGA_ID, '7c9e6679-7425-40de-944b-e07fc1f90ae7', self::TRACEPARENT);

        return $this->execucao->cancelar($osId, 'execucao_falhou', $saga);
    }

    private function span(string $nome): \OpenTelemetry\SDK\Trace\ImmutableSpan
    {
        foreach ($this->spansExportados() as $span) {
            if ($span->getName() === $nome) {
                return $span;
            }
        }

        $this->fail("Span '{$nome}' não foi exportado.");
    }
}
