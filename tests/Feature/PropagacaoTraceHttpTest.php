<?php

namespace Tests\Feature;

use OpenTelemetry\API\Trace\SpanKind;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\TestCase;

/**
 * O span de request continua o trace recebido em `traceparent`: uma chamada REST
 * entre serviços não quebra o trace distribuído.
 */
class PropagacaoTraceHttpTest extends TestCase
{
    use UsaTracerEmMemoria;

    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const SPAN_ID = 'b7ad6b7169203331';

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

    public function test_request_com_traceparent_continua_o_trace_recebido(): void
    {
        $this->getJson('/api/health', [
            'traceparent' => '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01',
        ])->assertOk();

        $request = $this->spanDeRequest();

        $this->assertSame(self::TRACE_ID, $request->getTraceId());
        $this->assertSame(self::SPAN_ID, $request->getParentSpanId());
    }

    public function test_request_sem_traceparent_abre_trace_novo(): void
    {
        $this->getJson('/api/health')->assertOk();

        $request = $this->spanDeRequest();

        $this->assertFalse($request->getParentContext()->isValid());
        $this->assertNotSame(self::TRACE_ID, $request->getTraceId());
    }

    public function test_traceparent_invalido_nao_derruba_a_requisicao(): void
    {
        $this->getJson('/api/health', ['traceparent' => 'lixo'])->assertOk();

        $this->assertFalse($this->spanDeRequest()->getParentContext()->isValid());
    }

    public function test_resposta_devolve_x_request_id(): void
    {
        $this->getJson('/api/health', ['X-Request-Id' => 'abc-123'])
            ->assertOk()
            ->assertHeader('X-Request-Id', 'abc-123');
    }

    private function spanDeRequest(): \OpenTelemetry\SDK\Trace\ImmutableSpan
    {
        foreach ($this->spansExportados() as $span) {
            if ($span->getKind() === SpanKind::KIND_SERVER) {
                return $span;
            }
        }

        $this->fail('O span de request não foi exportado.');
    }
}
