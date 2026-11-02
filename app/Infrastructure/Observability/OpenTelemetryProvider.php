<?php

namespace App\Infrastructure\Observability;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

class OpenTelemetryProvider
{
    private const SERVICO_PADRAO = 'oficina-execucao-service';

    private static ?TracerProviderInterface $provider = null;

    private static bool $shutdownRegistrado = false;

    public static function nomeServico(): string
    {
        return (string) (env('OTEL_SERVICE_NAME') ?: self::SERVICO_PADRAO);
    }

    public static function tracer(): TracerInterface
    {
        return self::provider()->getTracer(self::nomeServico());
    }

    public static function provider(): TracerProviderInterface
    {
        if (self::$provider !== null) {
            return self::$provider;
        }

        $endpoint = (string) (env('OTEL_EXPORTER_OTLP_ENDPOINT') ?: '');

        if ($endpoint === '') {
            // Sem endpoint configurado: provider no-op (não exporta, não quebra).
            self::$provider = new TracerProvider([], new AlwaysOnSampler());

            return self::$provider;
        }

        $headers = self::parseHeaders((string) (env('OTEL_EXPORTER_OTLP_HEADERS') ?: ''));

        $transport = (new OtlpHttpTransportFactory())->create(
            $endpoint . '/v1/traces',
            'application/x-protobuf',
            $headers
        );

        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => self::nomeServico(),
            ]))
        );

        self::$provider = new TracerProvider(
            [new BatchSpanProcessor(new SpanExporter($transport), ClockFactory::getDefault())],
            new AlwaysOnSampler(),
            $resource
        );

        // O BatchSpanProcessor enfileira os spans e só os envia no flush/shutdown.
        // Em PHP-FPM o processo morre ao fim da requisição, e o SDK não registra
        // esse encerramento sozinho — sem isto, a fila morre com o processo e
        // nenhum trace chega ao coletor.
        self::registrarShutdown();

        return self::$provider;
    }

    /**
     * Garante o envio da fila de spans quando o processo termina.
     */
    private static function registrarShutdown(): void
    {
        if (self::$shutdownRegistrado) {
            return;
        }

        self::$shutdownRegistrado = true;

        register_shutdown_function(static function (): void {
            $provider = self::$provider;

            if ($provider !== null && method_exists($provider, 'shutdown')) {
                $provider->shutdown();
            }
        });
    }

    /**
     * Envia imediatamente os spans já enfileirados. Chamado no terminate do
     * middleware HTTP (depois que a resposta foi entregue) e ao fim de cada
     * mensagem consumida (processo longo: o shutdown nunca chega).
     */
    public static function flush(): void
    {
        $provider = self::$provider;

        if ($provider !== null && method_exists($provider, 'forceFlush')) {
            $provider->forceFlush();
        }
    }

    /**
     * Converte "api-key=abc,outro=xyz" em ['api-key' => 'abc', 'outro' => 'xyz'].
     *
     * @return array<string, string>
     */
    private static function parseHeaders(string $raw): array
    {
        $headers = [];

        foreach (explode(',', $raw) as $par) {
            $par = trim($par);
            if ($par === '' || !str_contains($par, '=')) {
                continue;
            }

            [$chave, $valor] = explode('=', $par, 2);
            $headers[trim($chave)] = trim($valor);
        }

        return $headers;
    }
}
