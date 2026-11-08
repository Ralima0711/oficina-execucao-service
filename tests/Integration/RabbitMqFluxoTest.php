<?php

namespace Tests\Integration;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\FalhaAoPublicarEvento;
use App\Application\Services\ExecucaoService;
use App\Application\Services\FilaExecucaoService;
use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;
use App\Infrastructure\Messaging\RabbitMq\ConexaoRabbitMq;
use App\Infrastructure\Messaging\RabbitMq\ConsumidorDeComandos;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqPublicador;
use App\Infrastructure\Persistence\Mongo\MongoMensagensProcessadasRepository;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use App\Infrastructure\Persistence\Mongo\MongoTimelineRepository;
use Illuminate\Support\Facades\Redis;
use MongoDB\Database;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\Support\Ids;
use Tests\TestCase;

/**
 * Fluxo completo contra um RabbitMQ, um Redis e um MongoDB de verdade: um comando
 * chega pela fila, vira OS na fila de execução, o mecânico a atende, e os eventos
 * voltam ao orquestrador no mesmo trace. Roda dentro do compose:
 * `docker compose exec app vendor/bin/phpunit --testsuite Integration`.
 *
 * Usa filas e exchanges próprios (sufixo .test) com a mesma forma da topologia do
 * contrato, para nunca tocar nas filas de desenvolvimento. Sem broker acessível,
 * os testes são pulados.
 */
class RabbitMqFluxoTest extends TestCase
{
    use UsaTracerEmMemoria;

    private const SAGA_ID = '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f';
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const SPAN_DO_ORQUESTRADOR = 'b7ad6b7169203331';

    private const FILA_COMANDO = 'execucao.enviar-para-execucao.test';
    private const FILA_EVENTOS = 'os.saga.eventos.test';
    private const DLQ = 'dlq.execucao.enviar-para-execucao.test';
    private const FILA_CANCELAR = 'execucao.cancelar-execucao.test';
    private const DLQ_CANCELAR = 'dlq.execucao.cancelar-execucao.test';
    private const EXCHANGE_EVENTOS = 'oficina.eventos.test';
    private const EXCHANGE_DLX = 'oficina.dlx.test';

    private ConexaoRabbitMq $broker;

    private Database $mongo;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca os dados de desenvolvimento: bancos de teste e filas de teste.
        config([
            'database.redis.default.database' => '15',
            'mongodb.database'                => 'execucao_test',
            'rabbitmq.exchange_eventos'       => self::EXCHANGE_EVENTOS,
            'rabbitmq.fila_enviar'            => self::FILA_COMANDO,
            'rabbitmq.fila_cancelar'          => self::FILA_CANCELAR,
            'rabbitmq.timeout'                => 2,
        ]);

        try {
            $this->broker = new ConexaoRabbitMq();
            $this->declararTopologia();
            $this->mongo = $this->app->make(Database::class);
            $this->mongo->command(['ping' => 1]);
            Redis::ping();
        } catch (\Throwable) {
            $this->markTestSkipped('RabbitMQ, Redis ou MongoDB indisponível.');
        }

        $this->limparEstado();
        $this->app->make(MongoTimelineRepository::class)->criarIndices();
        $this->usarTracerEmMemoria();
    }

    protected function tearDown(): void
    {
        if (isset($this->broker)) {
            $this->removerTopologia();
            $this->limparEstado();
        }
        $this->restaurarTracer();

        parent::tearDown();
    }

    public function test_comando_vira_os_na_fila_e_os_eventos_voltam_no_mesmo_trace_e_na_mesma_saga(): void
    {
        $comando = $this->comando(['prioridade' => 3]);
        $this->publicarComando($comando);

        $this->assertSame(1, $this->consumidor()->executar(1, 5));

        $item = $this->app->make(FilaExecucaoRepositoryInterface::class)->listar()[0];
        $this->assertSame(Ids::os(10), $item->osId);
        $this->assertSame(3, $item->prioridade);
        $this->assertSame(self::SAGA_ID, $item->saga->sagaId);

        $this->app->make(FilaExecucaoService::class)->proxima(Ids::mecanico(7));
        $this->app->make(ExecucaoService::class)->finalizar(Ids::os(10), Ids::mecanico(7));

        $recebidas = $this->lerEventos(2);

        $this->assertSame(['execucao.iniciada', 'execucao.concluida'], array_column($recebidas, 'routingKey'));

        foreach ($recebidas as ['mensagem' => $mensagem, 'envelope' => $envelope]) {
            $this->assertSame(self::SAGA_ID, $envelope->sagaId);
            $this->assertSame($comando->messageId, $envelope->causationId, 'causationId aponta para o comando.');
            $this->assertSame(Ids::os(10), $envelope->body['osId']);
            $this->assertSame($item->execucaoId, $envelope->body['execucaoId']);
            $this->assertSame(self::TRACE_ID, $this->traceIdDe($envelope->traceparent), 'Mesmo trace do comando.');
            $this->assertSame(
                $envelope->traceparent,
                $mensagem->get('application_headers')->getNativeData()['traceparent'],
                'O traceparent também viaja nos headers do AMQP.'
            );
            $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $mensagem->get('delivery_mode'));
            $this->assertSame('application/json', $mensagem->get('content_type'));
        }
    }

    public function test_os_spans_formam_uma_cadeia_unica_do_comando_ate_os_eventos(): void
    {
        $this->publicarComando($this->comando());
        $this->consumidor()->executar(1, 5);
        $this->app->make(FilaExecucaoService::class)->proxima(Ids::mecanico(7));
        $this->app->make(ExecucaoService::class)->finalizar(Ids::os(10), Ids::mecanico(7));

        $consumidor = $this->span(self::FILA_COMANDO . ' process');
        $iniciada = $this->span('execucao.iniciada publish');
        $concluida = $this->span('execucao.concluida publish');

        // comando (span do orquestrador) → consumidor → publicação dos dois eventos
        $this->assertSame(self::SPAN_DO_ORQUESTRADOR, $consumidor->getParentSpanId());
        $this->assertSame($consumidor->getSpanId(), $iniciada->getParentSpanId());
        $this->assertSame($consumidor->getSpanId(), $concluida->getParentSpanId());

        foreach ([$consumidor, $iniciada, $concluida] as $span) {
            $this->assertSame(self::TRACE_ID, $span->getTraceId());
        }

        // o consumidor de cada evento (OS Service) seria filho do span de publicação
        $recebidas = $this->lerEventos(2);
        $this->assertSame(sprintf('00-%s-%s-01', self::TRACE_ID, $iniciada->getSpanId()), $recebidas[0]['envelope']->traceparent);
        $this->assertSame(sprintf('00-%s-%s-01', self::TRACE_ID, $concluida->getSpanId()), $recebidas[1]['envelope']->traceparent);
    }

    public function test_a_timeline_guarda_a_saga_e_o_trace_distribuido(): void
    {
        $this->publicarComando($this->comando());
        $this->consumidor()->executar(1, 5);
        $this->app->make(FilaExecucaoService::class)->proxima(Ids::mecanico(7));

        $evento = $this->app->make(MongoTimelineRepository::class)->porOs(Ids::os(10))[0];

        $this->assertSame(self::SAGA_ID, $evento->saga->sagaId);
        $this->assertSame(self::TRACE_ID, $evento->traceId);
    }

    public function test_comando_entregue_duas_vezes_e_tratado_uma_so(): void
    {
        $comando = $this->comando();
        $this->publicarComando($comando);
        $this->publicarComando($comando);

        $this->assertSame(2, $this->consumidor()->executar(2, 5));

        $this->assertCount(1, $this->app->make(FilaExecucaoRepositoryInterface::class)->listar());
        $this->assertSame(0, $this->contarMensagens(self::DLQ), 'Repetição não é erro: ack, nunca DLQ.');
    }

    public function test_mensagens_fora_do_contrato_vao_para_a_dlq_sem_travar_a_fila(): void
    {
        $this->publicarBruto('isto não é json');
        $this->publicarComando($this->comando(['osId' => '123']));
        $this->publicarComando($this->comando());

        $this->assertSame(3, $this->consumidor()->executar(3, 5));

        $this->assertSame(2, $this->contarMensagens(self::DLQ), 'As duas mensagens inválidas foram para a DLQ.');
        $this->assertCount(1, $this->app->make(FilaExecucaoRepositoryInterface::class)->listar(), 'A válida que veio depois foi tratada.');
        $this->assertSame(0, $this->contarMensagens(self::FILA_COMANDO));
    }

    public function test_o_traceparent_do_amqp_e_usado_quando_o_envelope_nao_o_traz(): void
    {
        $semTrace = Envelope::novo('EnviarParaExecucao', self::SAGA_ID, null, ['osId' => Ids::os(10), 'orcamentoId' => Ids::os(99)], null);
        $this->publicarBruto($semTrace->toJson(), ['traceparent' => '00-' . self::TRACE_ID . '-' . self::SPAN_DO_ORQUESTRADOR . '-01']);

        $this->consumidor()->executar(1, 5);

        $this->assertSame(self::SPAN_DO_ORQUESTRADOR, $this->span(self::FILA_COMANDO . ' process')->getParentSpanId());
    }

    public function test_publicar_numa_routing_key_sem_fila_ligada_falha_em_vez_de_perder_a_mensagem(): void
    {
        $publicador = new RabbitMqPublicador(new ConexaoRabbitMq());

        $this->expectException(FalhaAoPublicarEvento::class);
        $this->expectExceptionMessageMatches('/devolvida pelo broker/');

        $publicador->publicar('sem.fila.ligada', Envelope::novo('x', self::SAGA_ID, null, [], null));
    }

    public function test_broker_fora_do_ar_vira_falha_ao_publicar(): void
    {
        config(['rabbitmq.port' => 1]);

        $this->expectException(FalhaAoPublicarEvento::class);

        (new RabbitMqPublicador(new ConexaoRabbitMq()))->publicar('execucao.iniciada', Envelope::novo('x', self::SAGA_ID, null, [], null));
    }

    // ── compensação: CancelarExecucao ─────────────────────────────

    public function test_cancelamento_de_os_na_fila_publica_falhou_no_mesmo_trace_e_libera_o_execucao_id(): void
    {
        $envio = $this->comando();
        $this->publicarComando($envio);
        $this->consumidor()->executar(1, 5);
        $execucaoId = $this->app->make(FilaExecucaoRepositoryInterface::class)->listar()[0]->execucaoId;

        $cancelamento = $this->cancelamento();
        $this->publicarCancelamento($cancelamento);

        $this->assertSame(1, $this->consumidor()->executar(1, 5));

        $this->assertSame([], $this->app->make(FilaExecucaoRepositoryInterface::class)->listar());
        $this->assertSame(0, Redis::exists(\App\Infrastructure\Persistence\Redis\RedisFilaExecucaoRepository::chaveExecucao(Ids::os(10))), 'O execucaoId foi liberado.');

        [['routingKey' => $chave, 'envelope' => $envelope, 'mensagem' => $mensagem]] = $this->lerEventos(1);

        $this->assertSame('execucao.falhou', $chave);
        $this->assertSame(self::SAGA_ID, $envelope->sagaId);
        $this->assertSame($cancelamento->messageId, $envelope->causationId);
        $this->assertSame(['osId' => Ids::os(10), 'execucaoId' => $execucaoId, 'motivo' => 'cancelada'], $envelope->body);
        $this->assertSame(self::TRACE_ID, $this->traceIdDe($envelope->traceparent), 'Mesmo trace do cancelamento.');
        $this->assertSame($envelope->traceparent, $mensagem->get('application_headers')->getNativeData()['traceparent']);

        $this->assertSame(0, $this->contarMensagens(self::DLQ_CANCELAR));
        $this->assertSame(
            TipoEvento::EXECUCAO_FALHOU,
            $this->app->make(MongoTimelineRepository::class)->ultimoEventoDeEstado(Ids::os(10))->tipo
        );
    }

    public function test_cancelamento_de_reparo_em_andamento_libera_o_mecanico_e_publica_falhou(): void
    {
        $this->publicarComando($this->comando());
        $this->consumidor()->executar(1, 5);
        $this->app->make(FilaExecucaoService::class)->proxima(Ids::mecanico(7));

        $this->publicarCancelamento($this->cancelamento());
        $this->consumidor()->executar(1, 5);

        $this->assertNull($this->app->make(FilaExecucaoRepositoryInterface::class)->osEmExecucao(Ids::mecanico(7)));

        $recebidas = $this->lerEventos(2);
        $this->assertSame(['execucao.iniciada', 'execucao.falhou'], array_column($recebidas, 'routingKey'));
        $this->assertSame(Ids::mecanico(7), $recebidas[1]['envelope']->body['mecanicoId']);
    }

    public function test_cancelamento_entregue_duas_vezes_e_tratado_uma_so_e_a_os_concluida_nao_e_desfeita(): void
    {
        $this->publicarComando($this->comando());
        $this->consumidor()->executar(1, 5);

        $cancelamento = $this->cancelamento();
        $this->publicarCancelamento($cancelamento);
        $this->publicarCancelamento($cancelamento);
        $this->assertSame(2, $this->consumidor()->executar(2, 5));
        $this->lerEventos(1);

        // Outra OS, já concluída: o cancelamento não desfaz.
        $this->publicarComando($this->comando(['osId' => Ids::os(11)]));
        $this->consumidor()->executar(1, 5);
        $this->app->make(FilaExecucaoService::class)->proxima(Ids::mecanico(7));
        $this->app->make(ExecucaoService::class)->finalizar(Ids::os(11), Ids::mecanico(7));
        $this->lerEventos(2);

        $this->publicarCancelamento($this->cancelamento(['osId' => Ids::os(11)]));
        $this->consumidor()->executar(1, 5);

        $this->assertSame(0, $this->contarMensagens(self::FILA_EVENTOS), 'Nenhum execucao.falhou para a OS concluída.');
        $this->assertSame(0, $this->contarMensagens(self::DLQ_CANCELAR));
        $this->assertSame(
            \App\Domain\Execucao\ValueObjects\StatusExecucao::FINALIZADA,
            $this->app->make(MongoTimelineRepository::class)->ultimoEventoDeEstado(Ids::os(11))->statusNovo
        );
    }

    public function test_cancelamento_fora_do_contrato_vai_para_a_dlq_de_cancelamento_sem_travar_a_fila(): void
    {
        $this->publicarCancelamento($this->cancelamento(['osId' => '123']));
        $this->publicarComando($this->comando());

        $this->assertSame(2, $this->consumidor()->executar(2, 5));

        $this->assertSame(1, $this->contarMensagens(self::DLQ_CANCELAR));
        $this->assertCount(1, $this->app->make(FilaExecucaoRepositoryInterface::class)->listar(), 'O comando válido da outra fila foi tratado.');
    }

    // ── apoio ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $extraCorpo */
    private function cancelamento(array $extraCorpo = []): Envelope
    {
        return Envelope::novo(
            'CancelarExecucao',
            self::SAGA_ID,
            null,
            array_merge(['osId' => Ids::os(10), 'orcamentoId' => Ids::os(99), 'motivo' => 'execucao_falhou'], $extraCorpo),
            '00-' . self::TRACE_ID . '-' . self::SPAN_DO_ORQUESTRADOR . '-01'
        );
    }

    private function publicarCancelamento(Envelope $envelope): void
    {
        $this->broker->canal()->basic_publish(
            new AMQPMessage($envelope->toJson(), ['content_type' => 'application/json', 'delivery_mode' => 2, 'application_headers' => new AMQPTable(['traceparent' => $envelope->traceparent])]),
            '',
            self::FILA_CANCELAR
        );
    }

    private function comando(array $extraCorpo = []): Envelope
    {
        return Envelope::novo(
            'EnviarParaExecucao',
            self::SAGA_ID,
            null,
            array_merge(['osId' => Ids::os(10), 'orcamentoId' => Ids::os(99)], $extraCorpo),
            '00-' . self::TRACE_ID . '-' . self::SPAN_DO_ORQUESTRADOR . '-01'
        );
    }

    /** Como o OS Service: publica o comando direto na fila, com o traceparent também no header do AMQP. */
    private function publicarComando(Envelope $envelope): void
    {
        $this->publicarBruto($envelope->toJson(), array_filter(['traceparent' => $envelope->traceparent]));
    }

    private function publicarBruto(string $corpo, array $headers = []): void
    {
        $this->broker->canal()->basic_publish(
            new AMQPMessage($corpo, ['content_type' => 'application/json', 'delivery_mode' => 2, 'application_headers' => new AMQPTable($headers)]),
            '',
            self::FILA_COMANDO
        );
    }

    private function consumidor(): ConsumidorDeComandos
    {
        return $this->app->make(ConsumidorDeComandos::class);
    }

    /** @return array<int, array{routingKey: string, mensagem: AMQPMessage, envelope: Envelope}> */
    private function lerEventos(int $esperados): array
    {
        $canal = $this->broker->canal();
        $lidas = [];
        $limite = microtime(true) + 3;

        while (count($lidas) < $esperados && microtime(true) < $limite) {
            $mensagem = $canal->basic_get(self::FILA_EVENTOS, true);
            if ($mensagem === null) {
                usleep(50_000);

                continue;
            }

            $lidas[] = [
                'routingKey' => $mensagem->getRoutingKey(),
                'mensagem'   => $mensagem,
                'envelope'   => Envelope::fromJson($mensagem->getBody()),
            ];
        }

        $this->assertCount($esperados, $lidas, 'Os eventos não chegaram à fila do orquestrador.');

        return $lidas;
    }

    private function contarMensagens(string $fila): int
    {
        [, $mensagens] = $this->broker->canal()->queue_declare($fila, true);

        return (int) $mensagens;
    }

    private function traceIdDe(?string $traceparent): ?string
    {
        return $traceparent !== null ? explode('-', $traceparent)[1] : null;
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

    private function declararTopologia(): void
    {
        $canal = $this->broker->canal();

        $canal->exchange_declare(self::EXCHANGE_EVENTOS, 'topic', false, true, false);
        $canal->exchange_declare(self::EXCHANGE_DLX, 'direct', false, true, false);

        $canal->queue_declare(self::DLQ, false, true, false, false);
        $canal->queue_bind(self::DLQ, self::EXCHANGE_DLX, self::DLQ);

        $canal->queue_declare(self::FILA_COMANDO, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange'    => self::EXCHANGE_DLX,
            'x-dead-letter-routing-key' => self::DLQ,
        ]));

        $canal->queue_declare(self::DLQ_CANCELAR, false, true, false, false);
        $canal->queue_bind(self::DLQ_CANCELAR, self::EXCHANGE_DLX, self::DLQ_CANCELAR);

        $canal->queue_declare(self::FILA_CANCELAR, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange'    => self::EXCHANGE_DLX,
            'x-dead-letter-routing-key' => self::DLQ_CANCELAR,
        ]));

        $canal->queue_declare(self::FILA_EVENTOS, false, true, false, false);
        $canal->queue_bind(self::FILA_EVENTOS, self::EXCHANGE_EVENTOS, 'execucao.*');

        foreach ($this->todasAsFilas() as $fila) {
            $canal->queue_purge($fila);
        }
    }

    /** @return string[] */
    private function todasAsFilas(): array
    {
        return [self::DLQ, self::FILA_COMANDO, self::DLQ_CANCELAR, self::FILA_CANCELAR, self::FILA_EVENTOS];
    }

    private function removerTopologia(): void
    {
        try {
            $canal = $this->broker->canal();
            foreach ($this->todasAsFilas() as $fila) {
                $canal->queue_delete($fila);
            }
            $canal->exchange_delete(self::EXCHANGE_EVENTOS);
            $canal->exchange_delete(self::EXCHANGE_DLX);
        } catch (\Throwable) {
            // O broker caiu no meio do teste: não há o que limpar.
        }
    }

    private function limparEstado(): void
    {
        $chaves = Redis::keys('execucao:*');
        if ($chaves !== []) {
            Redis::del($chaves);
        }

        $this->mongo->selectCollection(MongoTimelineRepository::COLECAO)->drop();
        $this->mongo->selectCollection(MongoMensagensProcessadasRepository::COLECAO)->drop();
    }
}
