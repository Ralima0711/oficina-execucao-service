<?php

namespace Tests\Unit;

use App\Infrastructure\Observability\OpenTelemetryProvider;
use App\Infrastructure\Observability\Tracing;
use OpenTelemetry\API\Trace\TracerInterface;
use PHPUnit\Framework\TestCase;
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
