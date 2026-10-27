<?php

namespace Tests\Unit;

use App\Infrastructure\Messaging\TraceContextCarrier;
use App\Infrastructure\Observability\OpenTelemetryProvider;
use OpenTelemetry\API\Trace\Span;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\UsaTracerEmMemoria;

class TraceContextCarrierTest extends TestCase
{
    use UsaTracerEmMemoria;

    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const SPAN_ID = 'b7ad6b7169203331';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->usarTracerEmMemoria();
    }

    protected function tearDown(): void
    {
        $this->restaurarTracer();
        parent::tearDown();
    }

    public function test_extract_de_traceparent_conhecido_faz_o_span_herdar_trace_e_pai(): void
    {
        $contexto = TraceContextCarrier::extract(['traceparent' => self::TRACEPARENT]);

        $span = OpenTelemetryProvider::tracer()->spanBuilder('consumer')->setParent($contexto)->startSpan();
        $span->end();

        [$exportado] = $this->spansExportados();

        $this->assertSame(self::TRACE_ID, $exportado->getTraceId());
        $this->assertSame(self::SPAN_ID, $exportado->getParentSpanId());
    }

    public function test_inject_escreve_traceparent_do_span_corrente(): void
    {
        $span = OpenTelemetryProvider::tracer()->spanBuilder('producer')->startSpan();
        $escopo = $span->activate();

        try {
            $headers = TraceContextCarrier::inject(['outro' => 'valor']);
        } finally {
            $escopo->detach();
            $span->end();
        }

        $this->assertSame('valor', $headers['outro']);
        $this->assertSame(
            sprintf('00-%s-%s-01', $span->getContext()->getTraceId(), $span->getContext()->getSpanId()),
            $headers['traceparent']
        );
    }

    public function test_inject_sem_span_ativo_nao_acrescenta_header(): void
    {
        $this->assertSame([], TraceContextCarrier::inject());
    }

    public function test_extract_ignora_maiusculas_no_nome_do_header(): void
    {
        $contexto = TraceContextCarrier::extract(['Traceparent' => self::TRACEPARENT]);

        $span = Span::fromContext($contexto)->getContext();

        $this->assertTrue($span->isValid());
        $this->assertSame(self::TRACE_ID, $span->getTraceId());
    }

    public function test_extract_aceita_header_como_lista_de_valores(): void
    {
        $contexto = TraceContextCarrier::extract(['traceparent' => [self::TRACEPARENT]]);

        $this->assertSame(self::TRACE_ID, Span::fromContext($contexto)->getContext()->getTraceId());
    }

    public function test_traceparent_ausente_ou_invalido_gera_contexto_sem_pai(): void
    {
        foreach ([[], ['traceparent' => 'lixo'], ['traceparent' => '00-zzzz-yyyy-01']] as $headers) {
            $this->assertFalse(
                Span::fromContext(TraceContextCarrier::extract($headers))->getContext()->isValid(),
                'Header inválido não pode produzir um pai: ' . json_encode($headers)
            );
        }
    }

    public function test_extract_nao_herda_o_span_ambiente_do_worker(): void
    {
        $ambiente = OpenTelemetryProvider::tracer()->spanBuilder('ambiente')->startSpan();
        $escopo = $ambiente->activate();

        try {
            $contexto = TraceContextCarrier::extract([]);
        } finally {
            $escopo->detach();
            $ambiente->end();
        }

        $this->assertFalse(Span::fromContext($contexto)->getContext()->isValid());
    }
}
