<?php

namespace Tests\Concerns;

use App\Infrastructure\Observability\OpenTelemetryProvider;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use ReflectionProperty;

/**
 * Troca o provider do OpenTelemetry por um InMemoryExporter: os testes leem os
 * spans exportados, sem rede e sem coletor. No ambiente de teste o endpoint é
 * vazio, então sem isto o provider seria no-op e nada seria exportado.
 */
trait UsaTracerEmMemoria
{
    private InMemoryExporter $exportador;

    protected function usarTracerEmMemoria(): void
    {
        $this->exportador = new InMemoryExporter();

        $this->substituirProvider(new TracerProvider(
            [new SimpleSpanProcessor($this->exportador)],
            new AlwaysOnSampler()
        ));
    }

    protected function restaurarTracer(): void
    {
        $this->substituirProvider(null);
    }

    /**
     * @return \OpenTelemetry\SDK\Trace\ImmutableSpan[]
     */
    protected function spansExportados(): array
    {
        return $this->exportador->getSpans();
    }

    private function substituirProvider(?object $provider): void
    {
        (new ReflectionProperty(OpenTelemetryProvider::class, 'provider'))->setValue(null, $provider);
    }
}
