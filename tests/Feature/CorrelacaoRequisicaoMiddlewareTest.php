<?php

namespace Tests\Feature;

use App\Interface\Http\Middleware\CorrelacaoRequisicaoMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\TestCase;

/**
 * O span de request quando a aplicação falha, e o envio dos spans no terminate.
 * (A propagação do traceparent está em PropagacaoTraceHttpTest.)
 */
class CorrelacaoRequisicaoMiddlewareTest extends TestCase
{
    use UsaTracerEmMemoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usarTracerEmMemoria();

        Route::middleware('api')->get('/api/_teste/explode', function () {
            throw new \RuntimeException('Mongo fora do ar');
        });
        Route::middleware('api')->get('/api/_teste/indisponivel', fn () => response()->json([], 503));
    }

    protected function tearDown(): void
    {
        $this->restaurarTracer();
        parent::tearDown();
    }

    public function test_excecao_renderizada_pelo_laravel_vira_span_de_erro_com_status_500(): void
    {
        // O pipeline de rotas do Laravel converte a exceção em resposta 500 antes de
        // voltar ao middleware: o span é marcado como erro pelo status HTTP.
        $this->getJson('/api/_teste/explode')->assertStatus(500);

        $span = $this->spanDeRequest();

        $this->assertSame('GET /api/_teste/explode', $span->getName());
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(500, $span->getAttributes()->get('http.status_code'));
    }

    public function test_excecao_que_chega_ao_middleware_marca_o_span_registra_a_excecao_e_e_relancada(): void
    {
        $middleware = new CorrelacaoRequisicaoMiddleware();
        $falha = new \RuntimeException('Mongo fora do ar');

        try {
            $middleware->handle(Request::create('/api/ordens', 'POST'), fn () => throw $falha);
            $this->fail('A exceção deveria ter sido relançada.');
        } catch (\RuntimeException $e) {
            $this->assertSame($falha, $e);
        }

        $span = $this->spanDeRequest();

        $this->assertSame('POST /api/ordens', $span->getName());
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('Mongo fora do ar', $span->getStatus()->getDescription());
        $this->assertTrue($span->hasEnded(), 'O span fecha mesmo quando a requisição lança exceção.');

        $eventos = $span->getEvents();
        $this->assertCount(1, $eventos);
        $this->assertSame('exception', $eventos[0]->getName());
        $this->assertSame(\RuntimeException::class, $eventos[0]->getAttributes()->get('exception.type'));

        // O escopo foi desfeito: nada fica como span corrente depois da falha.
        $this->assertFalse(\OpenTelemetry\API\Trace\Span::getCurrent()->getContext()->isValid());
    }

    public function test_depois_da_falha_o_span_de_request_nao_fica_como_corrente(): void
    {
        $this->getJson('/api/_teste/explode')->assertStatus(500);

        // Se o escopo ficasse ativo, a próxima requisição viraria filha da anterior.
        $this->getJson('/api/health')->assertOk();

        $requests = array_values(array_filter(
            $this->spansExportados(),
            fn ($s) => $s->getKind() === SpanKind::KIND_SERVER
        ));

        $this->assertCount(2, $requests);
        $this->assertFalse($requests[1]->getParentContext()->isValid());
        $this->assertNotSame($requests[0]->getTraceId(), $requests[1]->getTraceId());
    }

    public function test_resposta_5xx_marca_o_span_como_erro_e_guarda_o_status_http(): void
    {
        $this->getJson('/api/_teste/indisponivel')->assertStatus(503);

        $span = $this->spanDeRequest();

        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(503, $span->getAttributes()->get('http.status_code'));
    }

    public function test_terminate_envia_os_spans_que_estavam_na_fila_do_batch_processor(): void
    {
        // Como em produção: o BatchSpanProcessor só exporta no flush.
        $exportador = new InMemoryExporter();
        $this->substituirProvider(new TracerProvider(
            [new BatchSpanProcessor($exportador, ClockFactory::getDefault())],
            new AlwaysOnSampler()
        ));

        $middleware = new CorrelacaoRequisicaoMiddleware();
        $request = Request::create('/api/health', 'GET');
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame([], $exportador->getSpans(), 'Antes do terminate o span ainda está na fila.');

        $middleware->terminate($request, $response);

        $this->assertCount(1, $exportador->getSpans());
        $this->assertSame(SpanKind::KIND_SERVER, $exportador->getSpans()[0]->getKind());
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
