<?php

namespace Tests\Unit;

use App\Application\Messaging\FalhaAoPublicarEvento;
use App\Application\Services\EventosDeExecucao;
use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\ValueObjects\ContextoSaga;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\Support\Ids;
use Tests\Support\PublicadorEmMemoria;
use Tests\TestCase;

class EventosDeExecucaoTest extends TestCase
{
    use UsaTracerEmMemoria;

    private const SAGA_ID = '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f';
    private const COMANDO_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const SPAN_DO_CONSUMIDOR = 'b7ad6b7169203331';

    private PublicadorEmMemoria $publicador;

    private EventosDeExecucao $eventos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usarTracerEmMemoria();

        $this->publicador = new PublicadorEmMemoria();
        $this->eventos = new EventosDeExecucao($this->publicador);
    }

    protected function tearDown(): void
    {
        $this->restaurarTracer();
        parent::tearDown();
    }

    public function test_iniciada_segue_o_contrato_e_aponta_para_o_comando_que_a_causou(): void
    {
        $this->eventos->iniciada($this->evento(TipoEvento::EXECUCAO_INICIADA, '14:00:00', $this->saga()));

        $this->assertSame(['execucao.iniciada'], $this->publicador->routingKeys());

        $envelope = $this->publicador->publicadas[0]['envelope'];
        $this->assertSame('execucao.iniciada', $envelope->type);
        $this->assertSame(self::SAGA_ID, $envelope->sagaId);
        $this->assertSame(self::COMANDO_ID, $envelope->causationId);
        $this->assertSame(1, $envelope->version);
        $this->assertSame([
            'osId'       => Ids::os(10),
            'execucaoId' => 'exec-1',
            'mecanicoId' => Ids::mecanico(7),
            'iniciadaEm' => '2026-11-02T14:00:00Z',
        ], $envelope->body);
    }

    public function test_concluida_leva_inicio_e_fim(): void
    {
        $this->eventos->concluida(
            $this->evento(TipoEvento::EXECUCAO_FINALIZADA, '16:30:00', $this->saga()),
            new \DateTimeImmutable('2026-11-02 14:00:00', new \DateTimeZone('UTC'))
        );

        $envelope = $this->publicador->publicadas[0]['envelope'];

        $this->assertSame('execucao.concluida', $envelope->type);
        $this->assertSame('2026-11-02T14:00:00Z', $envelope->body['iniciadaEm']);
        $this->assertSame('2026-11-02T16:30:00Z', $envelope->body['concluidaEm']);
    }

    public function test_o_span_de_publicacao_e_filho_do_trace_da_saga_e_o_envelope_carrega_o_proprio_span(): void
    {
        $this->eventos->iniciada($this->evento(TipoEvento::EXECUCAO_INICIADA, '14:00:00', $this->saga()));

        $producer = $this->span('execucao.iniciada publish');

        $this->assertSame(SpanKind::KIND_PRODUCER, $producer->getKind());
        $this->assertSame(self::TRACE_ID, $producer->getTraceId(), 'Mesmo trace do comando recebido.');
        $this->assertSame(self::SPAN_DO_CONSUMIDOR, $producer->getParentSpanId(), 'Filho do span do consumidor, não da requisição HTTP.');

        $this->assertSame(
            sprintf('00-%s-%s-01', self::TRACE_ID, $producer->getSpanId()),
            $this->publicador->publicadas[0]['envelope']->traceparent,
            'O consumidor do evento (OS Service) vira filho do span de publicação.'
        );
    }

    public function test_publica_no_trace_da_saga_mesmo_com_outro_span_ativo_na_requisicao(): void
    {
        $requisicao = \App\Infrastructure\Observability\OpenTelemetryProvider::tracer()->spanBuilder('POST /api/execucao/fila/proxima')->startSpan();
        $escopo = $requisicao->activate();

        try {
            $this->eventos->iniciada($this->evento(TipoEvento::EXECUCAO_INICIADA, '14:00:00', $this->saga()));
        } finally {
            $escopo->detach();
            $requisicao->end();
        }

        $producer = $this->span('execucao.iniciada publish');

        $this->assertSame(self::TRACE_ID, $producer->getTraceId());
        $this->assertNotSame($requisicao->getContext()->getTraceId(), $producer->getTraceId());
    }

    public function test_sem_saga_nao_publica_nada(): void
    {
        $this->eventos->iniciada($this->evento(TipoEvento::EXECUCAO_INICIADA, '14:00:00', null));
        $this->eventos->concluida($this->evento(TipoEvento::EXECUCAO_FINALIZADA, '15:00:00', null), new \DateTimeImmutable());

        $this->assertSame([], $this->publicador->publicadas);
        $this->assertSame([], $this->spansExportados());
    }

    public function test_saga_sem_traceparent_publica_do_mesmo_jeito_em_trace_novo(): void
    {
        $this->eventos->iniciada($this->evento(TipoEvento::EXECUCAO_INICIADA, '14:00:00', new ContextoSaga(self::SAGA_ID, self::COMANDO_ID, null)));

        $this->assertCount(1, $this->publicador->publicadas);
        $this->assertNotNull($this->publicador->publicadas[0]['envelope']->traceparent);
    }

    public function test_falha_do_broker_e_repropagada_e_marca_o_span_com_erro(): void
    {
        $this->publicador->falhar = true;

        try {
            $this->eventos->iniciada($this->evento(TipoEvento::EXECUCAO_INICIADA, '14:00:00', $this->saga()));
            $this->fail('A falha do broker deveria ser repropagada.');
        } catch (FalhaAoPublicarEvento) {
        }

        $this->assertSame(StatusCode::STATUS_ERROR, $this->span('execucao.iniciada publish')->getStatus()->getCode());
    }

    private function saga(): ContextoSaga
    {
        return new ContextoSaga(self::SAGA_ID, self::COMANDO_ID, '00-' . self::TRACE_ID . '-' . self::SPAN_DO_CONSUMIDOR . '-01');
    }

    private function evento(TipoEvento $tipo, string $hora, ?ContextoSaga $saga): EventoTimeline
    {
        return new EventoTimeline(
            osId: Ids::os(10),
            tipo: $tipo,
            statusAnterior: StatusExecucao::APROVADA,
            statusNovo: StatusExecucao::EM_EXECUCAO,
            mecanicoId: Ids::mecanico(7),
            duracaoSegundos: null,
            observacao: null,
            traceId: null,
            ocorridoEm: new \DateTimeImmutable("2026-11-02 {$hora}", new \DateTimeZone('UTC')),
            execucaoId: 'exec-1',
            saga: $saga,
        );
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
