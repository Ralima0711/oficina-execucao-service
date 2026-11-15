<?php

namespace Tests\Unit;

use App\Infrastructure\Observability\OpenTelemetryProvider;
use App\Infrastructure\Observability\Tracing;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class OpenTelemetryProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->zerarProvider();
    }

    protected function tearDown(): void
    {
        $this->definirEndpoint(null);
        $this->zerarProvider();
        parent::tearDown();
    }

    public function test_sem_endpoint_e_no_op_e_o_servico_funciona(): void
    {
        $this->definirEndpoint(null);

        $this->assertInstanceOf(TracerInterface::class, OpenTelemetryProvider::tracer());
        $this->assertSame('ok', Tracing::executar('x', [], fn () => 'ok'));
    }

    public function test_endpoint_invalido_nao_derruba_o_servico_e_vira_no_op(): void
    {
        // O SDK recusa hostname com underscore: antes isso lançava exceção a cada requisição.
        $this->definirEndpoint('http://host_com_underscore:4318');

        $this->assertInstanceOf(TracerInterface::class, OpenTelemetryProvider::tracer());
        $this->assertSame('ok', Tracing::executar('x', [], fn () => 'ok'));
    }

    public function test_endpoint_valido_cria_o_exportador(): void
    {
        $this->definirEndpoint('http://coletor:4318');

        $this->assertInstanceOf(TracerInterface::class, OpenTelemetryProvider::tracer());

        // Zera antes do shutdown: o flush não pode tentar rede.
        $this->zerarProvider();
    }

    public function test_headers_do_otlp_sao_lidos_como_chave_valor_ignorando_lixo(): void
    {
        $parse = new ReflectionMethod(OpenTelemetryProvider::class, 'parseHeaders');

        $this->assertSame(
            ['api-key' => 'abc', 'x-org' => 'oficina=77'],
            $parse->invoke(null, ' api-key = abc ,, sem-igual, x-org=oficina=77 ')
        );
        $this->assertSame([], $parse->invoke(null, ''));
    }

    public function test_recriar_o_provider_otlp_nao_registra_o_shutdown_de_novo(): void
    {
        $this->definirEndpoint('http://coletor:4318');
        $this->definirVariavel('OTEL_EXPORTER_OTLP_HEADERS', 'api-key=abc');
        $registrado = new ReflectionProperty(OpenTelemetryProvider::class, 'shutdownRegistrado');
        $registrado->setValue(null, false);

        try {
            $primeiro = OpenTelemetryProvider::provider();
            $this->assertTrue($registrado->getValue(), 'O primeiro provider OTLP registra o shutdown.');

            $this->zerarProvider();
            $segundo = OpenTelemetryProvider::provider();

            $this->assertNotSame($primeiro, $segundo, 'Um provider novo foi criado.');
            $this->assertTrue($registrado->getValue());
        } finally {
            $this->definirVariavel('OTEL_EXPORTER_OTLP_HEADERS', null);
            // Zera antes do shutdown: o flush não pode tentar rede.
            $this->zerarProvider();
        }
    }

    public function test_flush_exporta_o_que_o_batch_processor_tinha_enfileirado(): void
    {
        $exportador = new InMemoryExporter();
        (new ReflectionProperty(OpenTelemetryProvider::class, 'provider'))->setValue(null, new TracerProvider(
            [new BatchSpanProcessor($exportador, ClockFactory::getDefault())],
            new AlwaysOnSampler()
        ));

        Tracing::executar('execucao.finalizar', [], fn () => null);
        $this->assertSame([], $exportador->getSpans(), 'O batch processor ainda não exportou.');

        OpenTelemetryProvider::flush();

        $this->assertSame(['execucao.finalizar'], array_map(fn ($s) => $s->getName(), $exportador->getSpans()));
    }

    public function test_flush_antes_de_existir_provider_nao_cria_um(): void
    {
        OpenTelemetryProvider::flush();

        $this->assertNull((new ReflectionProperty(OpenTelemetryProvider::class, 'provider'))->getValue());
    }

    public function test_erro_e_finalizar_sem_span_nao_mexem_no_span_corrente(): void
    {
        $this->definirEndpoint(null);
        $externo = Tracing::iniciar('externo');

        // Chamadas com null (span que não chegou a abrir) não podem desempilhar o escopo de outro span.
        Tracing::erro(null, new \RuntimeException('x'));
        Tracing::finalizar(null);

        $this->assertSame($externo->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        $this->assertSame($externo->getContext()->getTraceId(), Tracing::traceIdAtual());

        Tracing::finalizar($externo);
        $this->assertNull(Tracing::traceIdAtual());
    }

    private function definirVariavel(string $nome, ?string $valor): void
    {
        foreach (['_ENV', '_SERVER'] as $global) {
            if ($valor === null) {
                unset($GLOBALS[$global][$nome]);
            } else {
                $GLOBALS[$global][$nome] = $valor;
            }
        }

        $valor === null ? putenv($nome) : putenv("{$nome}={$valor}");
    }

    private function definirEndpoint(?string $endpoint): void
    {
        foreach (['_ENV', '_SERVER'] as $global) {
            if ($endpoint === null) {
                unset($GLOBALS[$global]['OTEL_EXPORTER_OTLP_ENDPOINT']);
            } else {
                $GLOBALS[$global]['OTEL_EXPORTER_OTLP_ENDPOINT'] = $endpoint;
            }
        }

        $endpoint === null ? putenv('OTEL_EXPORTER_OTLP_ENDPOINT') : putenv("OTEL_EXPORTER_OTLP_ENDPOINT={$endpoint}");
    }

    private function zerarProvider(): void
    {
        (new ReflectionProperty(OpenTelemetryProvider::class, 'provider'))->setValue(null, null);
    }
}
