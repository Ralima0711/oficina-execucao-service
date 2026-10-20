<?php

namespace Tests\Feature;

use App\Infrastructure\Messaging\MessageTracing;
use App\Infrastructure\Observability\ContextoObservabilidadeProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\TestCase;

class MessageTracingTest extends TestCase
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

    public function test_consumer_herda_trace_e_pai_do_traceparent_recebido(): void
    {
        MessageTracing::consumir('execucao.enfileirar', ['traceparent' => self::TRACEPARENT], fn () => null);

        $consumer = $this->spanPorNome('execucao.enfileirar process');

        $this->assertSame(self::TRACE_ID, $consumer->getTraceId());
        $this->assertSame(self::SPAN_ID, $consumer->getParentSpanId());
        $this->assertSame(SpanKind::KIND_CONSUMER, $consumer->getKind());
    }

    public function test_publicar_e_consumir_ficam_no_mesmo_trace_com_producer_como_pai(): void
    {
        $headers = null;

        MessageTracing::publicar('execucao.finalizada', function (array $h) use (&$headers) {
            $headers = $h;
        });

        $this->assertArrayHasKey('traceparent', $headers);

        MessageTracing::consumir('execucao.finalizada', $headers, fn () => null);

        $producer = $this->spanPorNome('execucao.finalizada publish');
        $consumer = $this->spanPorNome('execucao.finalizada process');

        $this->assertSame(SpanKind::KIND_PRODUCER, $producer->getKind());
        $this->assertSame(SpanKind::KIND_CONSUMER, $consumer->getKind());
        $this->assertSame($producer->getTraceId(), $consumer->getTraceId());
        $this->assertSame($producer->getSpanId(), $consumer->getParentSpanId());
    }

    public function test_span_interno_do_caso_de_uso_fica_filho_do_consumer(): void
    {
        MessageTracing::consumir('execucao.enfileirar', ['traceparent' => self::TRACEPARENT], function () {
            \App\Infrastructure\Observability\Tracing::finalizar(
                \App\Infrastructure\Observability\Tracing::iniciar('FilaExecucaoService::enfileirar')
            );
        });

        $interno = $this->spanPorNome('FilaExecucaoService::enfileirar');
        $consumer = $this->spanPorNome('execucao.enfileirar process');

        $this->assertSame(self::TRACE_ID, $interno->getTraceId());
        $this->assertSame($consumer->getSpanId(), $interno->getParentSpanId());
    }

    public function test_log_emitido_no_consumer_leva_trace_id_span_id_e_service_name(): void
    {
        $registro = null;

        MessageTracing::consumir('execucao.enfileirar', ['traceparent' => self::TRACEPARENT], function () use (&$registro) {
            $registro = (new ContextoObservabilidadeProcessor())(new LogRecord(
                new \DateTimeImmutable(),
                'testing',
                Level::Info,
                'dentro_do_consumer'
            ));
        });

        $json = json_decode((new JsonFormatter())->format($registro), true);
        $consumer = $this->spanPorNome('execucao.enfileirar process');

        $this->assertSame(self::TRACE_ID, $json['context']['trace.id']);
        $this->assertSame($consumer->getSpanId(), $json['context']['span.id']);
        $this->assertSame('oficina-execucao-service', $json['context']['service.name']);
    }

    public function test_processor_nao_sobrescreve_trace_id_ja_presente_no_contexto(): void
    {
        $registro = (new ContextoObservabilidadeProcessor())(new LogRecord(
            new \DateTimeImmutable(),
            'testing',
            Level::Info,
            'fora_de_span',
            ['trace.id' => 'do-middleware']
        ));

        $this->assertSame('do-middleware', $registro->context['trace.id']);
        $this->assertArrayNotHasKey('span.id', $registro->context);
    }

    public function test_falha_no_caso_de_uso_marca_o_span_com_erro_e_repropaga(): void
    {
        try {
            MessageTracing::consumir('execucao.enfileirar', ['traceparent' => self::TRACEPARENT], function () {
                throw new \RuntimeException('falha simulada');
            });
            $this->fail('A exceção deveria ser repropagada.');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada', $e->getMessage());
        }

        $consumer = $this->spanPorNome('execucao.enfileirar process');

        $this->assertSame(StatusCode::STATUS_ERROR, $consumer->getStatus()->getCode());
        $this->assertSame(self::TRACE_ID, $consumer->getTraceId());
    }

    public function test_mensagem_sem_traceparent_abre_trace_novo(): void
    {
        MessageTracing::consumir('execucao.enfileirar', [], fn () => null);

        $consumer = $this->spanPorNome('execucao.enfileirar process');

        $this->assertFalse($consumer->getParentContext()->isValid());
        $this->assertNotSame(self::TRACE_ID, $consumer->getTraceId());
    }

    private function spanPorNome(string $nome): \OpenTelemetry\SDK\Trace\ImmutableSpan
    {
        foreach ($this->spansExportados() as $span) {
            if ($span->getName() === $nome) {
                return $span;
            }
        }

        $this->fail("Span '{$nome}' não foi exportado.");
    }
}
