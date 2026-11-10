<?php

namespace Tests\Bdd;

use App\Application\Messaging\Envelope;
use App\Infrastructure\Messaging\RabbitMq\ConexaoRabbitMq;
use App\Infrastructure\Messaging\RabbitMq\ConsumidorDeComandos;
use App\Infrastructure\Persistence\Mongo\MongoMensagensProcessadasRepository;
use App\Infrastructure\Persistence\Mongo\MongoTimelineRepository;
use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use MongoDB\Database;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\Concerns\UsaTracerEmMemoria;

/**
 * Passos da feature da Execução, de ponta a ponta dentro do serviço: o comando entra pelo
 * RabbitMQ e passa pelo consumidor de verdade, o mecânico age pela API HTTP, e os eventos
 * são lidos da fila que faz o papel do orquestrador. Redis e MongoDB também são os reais.
 *
 * Tudo com nomes próprios, para nunca tocar no ambiente de desenvolvimento nem nos testes
 * de integração: filas e exchanges com sufixo .bdd, Redis db 14 e o banco execucao_bdd.
 *
 * Os nomes da feature ("A", "João") viram UUIDs novos a cada cenário.
 */
class ExecucaoContext implements Context
{
    use UsaTracerEmMemoria;

    private const EXCHANGE_EVENTOS = 'oficina.eventos.bdd';
    private const EXCHANGE_DLX = 'oficina.dlx.bdd';
    private const FILA_ENVIAR = 'execucao.enviar-para-execucao.bdd';
    private const FILA_CANCELAR = 'execucao.cancelar-execucao.bdd';
    private const FILA_ORQUESTRADOR = 'os.saga.eventos.bdd';
    private const DLQS = ['dlq.execucao.enviar-para-execucao.bdd', 'dlq.execucao.cancelar-execucao.bdd'];

    /** Quanto esperar um evento chegar (e quanto esperar para afirmar que um evento NÃO chegou). */
    private const ESPERA_SEGUNDOS = 3.0;

    private Application $app;

    private ConexaoRabbitMq $broker;

    private string $sagaId;

    private string $traceId;

    /** @var array<string, string> nome na feature => UUID */
    private array $ids = [];

    /** @var Envelope[] comandos que o "orquestrador" mandou */
    private array $comandos = [];

    /** @var array<int, array{routingKey: string, envelope: Envelope}> eventos que o "orquestrador" recebeu */
    private array $recebidos = [];

    // ── ciclo de vida ─────────────────────────────────────────────

    #[BeforeScenario]
    public function prepararAmbiente(): void
    {
        $this->app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $this->app->make(ConsoleKernel::class)->bootstrap();

        config([
            'database.redis.default.database' => '14',
            'mongodb.database'                => 'execucao_bdd',
            'rabbitmq.exchange_eventos'       => self::EXCHANGE_EVENTOS,
            'rabbitmq.fila_enviar'            => self::FILA_ENVIAR,
            'rabbitmq.fila_cancelar'          => self::FILA_CANCELAR,
            'rabbitmq.timeout'                => 2,
            // API e worker rodam no mesmo processo aqui: sem isto, o log JSON de cada passo
            // polui a saída do Behat (e o contexto de log da requisição vaza para o worker).
            'logging.default'                 => 'null',
        ]);

        // Um tracer de verdade (em memória): sem coletor configurado o provider seria no-op.
        $this->usarTracerEmMemoria();

        $this->sagaId = (string) Str::uuid();
        $this->traceId = bin2hex(random_bytes(16));
        $this->ids = [];
        $this->comandos = [];
        $this->recebidos = [];

        $this->broker = new ConexaoRabbitMq();
        $this->declararTopologia();
        $this->limparBancos();
        $this->app->make(MongoTimelineRepository::class)->criarIndices();
    }

    #[AfterScenario]
    public function desmontarAmbiente(): void
    {
        try {
            $canal = $this->broker->canal();
            foreach ([...self::DLQS, self::FILA_ENVIAR, self::FILA_CANCELAR, self::FILA_ORQUESTRADOR] as $fila) {
                $canal->queue_delete($fila);
            }
            $canal->exchange_delete(self::EXCHANGE_EVENTOS);
            $canal->exchange_delete(self::EXCHANGE_DLX);
        } catch (\Throwable) {
            // O broker caiu no meio do cenário: não há o que limpar.
        }

        $this->limparBancos();
        $this->restaurarTracer();
    }

    // ── orquestrador ──────────────────────────────────────────────

    #[Given('que o orquestrador enviou a OS :os para execução com prioridade :prioridade')]
    public function oOrquestradorEnviouAOs(string $os, int $prioridade): void
    {
        $this->publicarComando(self::FILA_ENVIAR, 'EnviarParaExecucao', [
            'osId'        => $this->id($os),
            'orcamentoId' => (string) Str::uuid(),
            'prioridade'  => $prioridade,
        ]);

        [$status, $fila] = $this->http('GET', '/api/execucao/fila');
        $this->garantir($status === 200 && in_array($this->id($os), array_column($fila, 'osId'), true), "A OS {$os} não entrou na fila.");
    }

    #[When('o orquestrador mandar cancelar a execução da OS :os com o motivo :motivo')]
    public function oOrquestradorMandarCancelar(string $os, string $motivo): void
    {
        $this->publicarComando(self::FILA_CANCELAR, 'CancelarExecucao', [
            'osId'        => $this->id($os),
            'orcamentoId' => (string) Str::uuid(),
            'motivo'      => $motivo,
        ]);

        $this->garantir($this->contarMensagens(self::DLQS[1]) === 0, 'O CancelarExecucao foi parar na DLQ.');
    }

    // ── mecânico ──────────────────────────────────────────────────

    #[When('o mecânico :mecanico puxar a próxima OS da fila')]
    public function oMecanicoPuxarAProxima(string $mecanico): void
    {
        [$status, $corpo] = $this->http('POST', '/api/execucao/fila/proxima', ['mecanicoId' => $this->id($mecanico)]);

        $this->garantir($status === 200, "O mecânico {$mecanico} não conseguiu puxar a OS (HTTP {$status}).");
    }

    #[Given('que o mecânico :mecanico puxou a OS :os')]
    public function oMecanicoPuxouAOs(string $mecanico, string $os): void
    {
        [$status, $corpo] = $this->http('POST', '/api/execucao/fila/proxima', ['mecanicoId' => $this->id($mecanico)]);

        $this->garantir($status === 200 && ($corpo['osId'] ?? null) === $this->id($os), "O mecânico {$mecanico} deveria ter puxado a OS {$os} (HTTP {$status}).");
    }

    #[When('o mecânico :mecanico finalizar o reparo da OS :os')]
    #[Given('que o mecânico :mecanico finalizou o reparo da OS :os')]
    public function oMecanicoFinalizar(string $mecanico, string $os): void
    {
        [$status] = $this->http('POST', "/api/execucao/os/{$this->id($os)}/finalizar", ['mecanicoId' => $this->id($mecanico)]);

        $this->garantir($status === 200, "A finalização da OS {$os} falhou (HTTP {$status}).");
    }

    #[When('o mecânico :mecanico informar que o reparo da OS :os falhou com o motivo :motivo')]
    public function oMecanicoInformarFalha(string $mecanico, string $os, string $motivo): void
    {
        [$status] = $this->http('POST', "/api/execucao/os/{$this->id($os)}/falhar", ['mecanicoId' => $this->id($mecanico), 'motivo' => $motivo]);

        $this->garantir($status === 200, "Informar a falha da OS {$os} falhou (HTTP {$status}).");
    }

    #[Then('o mecânico :mecanico deve estar livre para puxar outra OS')]
    public function oMecanicoDeveEstarLivre(string $mecanico): void
    {
        // Mecânico ocupado responde 409; livre, com a fila vazia, responde 404.
        [$status] = $this->http('POST', '/api/execucao/fila/proxima', ['mecanicoId' => $this->id($mecanico)]);

        $this->garantir($status === 404, "O mecânico {$mecanico} continua preso à OS (HTTP {$status}, esperado 404 de fila vazia).");
    }

    // ── o que o orquestrador recebe ───────────────────────────────

    #[Then('o orquestrador deve receber os eventos :eventos da OS :os')]
    public function oOrquestradorDeveReceberOsEventos(string $eventos, string $os): void
    {
        $esperados = array_map('trim', explode(',', $eventos));
        $recebidos = array_column($this->esperarEventosDaOs($os, count($esperados)), 'routingKey');

        $this->garantir($recebidos === $esperados, sprintf('Esperava %s da OS %s, recebeu %s.', implode(', ', $esperados), $os, implode(', ', $recebidos) ?: 'nada'));
    }

    #[Then('o orquestrador deve receber :evento da OS :os com o motivo :motivo')]
    public function oOrquestradorDeveReceberComMotivo(string $evento, string $os, string $motivo): void
    {
        $envelope = $this->esperarEvento($evento, $os);

        $this->garantir($envelope !== null, "O orquestrador não recebeu {$evento} da OS {$os}.");
        $this->garantir(($envelope->body['motivo'] ?? null) === $motivo, sprintf('Motivo "%s", esperado "%s".', $envelope->body['motivo'] ?? '', $motivo));
        $this->garantir($envelope->sagaId === $this->sagaId, 'O evento saiu em outra saga.');
        $this->garantir($this->traceIdDe($envelope) === $this->traceId, 'O evento saiu em outro trace.');
    }

    #[Then('o orquestrador não deve receber :evento da OS :os')]
    public function oOrquestradorNaoDeveReceber(string $evento, string $os): void
    {
        $this->garantir($this->esperarEvento($evento, $os) === null, "O orquestrador recebeu {$evento} da OS {$os}, e não deveria.");
    }

    #[Then('os eventos devem estar na mesma saga e no mesmo trace do comando')]
    public function osEventosDevemEstarNaMesmaSagaETrace(): void
    {
        $this->garantir($this->recebidos !== [], 'Nenhum evento recebido.');
        $comando = $this->comandos[0];

        foreach ($this->recebidos as ['routingKey' => $chave, 'envelope' => $envelope]) {
            $this->garantir($envelope->sagaId === $this->sagaId, "{$chave} saiu em outra saga.");
            $this->garantir($envelope->causationId === $comando->messageId, "{$chave} não aponta para o comando que o causou (causationId).");
            $this->garantir($this->traceIdDe($envelope) === $this->traceId, "{$chave} saiu em outro trace.");
        }
    }

    // ── estado da Execução ────────────────────────────────────────

    #[Then('a OS :os não deve estar mais na fila')]
    public function aOsNaoDeveEstarNaFila(string $os): void
    {
        [, $fila] = $this->http('GET', '/api/execucao/fila');

        $this->garantir(!in_array($this->id($os), array_column($fila, 'osId'), true), "A OS {$os} continua na fila.");
    }

    #[Then('a timeline da OS :os deve terminar em :status')]
    public function aTimelineDeveTerminarEm(string $os, string $status): void
    {
        $estados = array_values(array_filter($this->timeline($os), fn (array $e) => $e['statusNovo'] !== null));
        $ultimo = end($estados)['statusNovo'] ?? null;

        $this->garantir($ultimo === $status, "A OS {$os} está em {$ultimo}, esperado {$status}.");
    }

    #[Then('a tentativa de cancelamento deve ficar registrada na timeline da OS :os')]
    public function aTentativaDeveFicarRegistrada(string $os): void
    {
        $eventos = $this->timeline($os);
        $ultimo = end($eventos);

        $this->garantir(
            $ultimo['tipo'] === 'NOTA' && str_contains((string) $ultimo['observacao'], 'CancelarExecucao'),
            "O último registro da OS {$os} não é a tentativa de cancelamento."
        );
    }

    // ── apoio ─────────────────────────────────────────────────────

    /** Como o OS Service: publica o comando direto na fila, com o traceparent também no header do AMQP; o worker o trata. */
    private function publicarComando(string $fila, string $tipo, array $corpo): void
    {
        $envelope = Envelope::novo($tipo, $this->sagaId, null, $corpo, sprintf('00-%s-%s-01', $this->traceId, bin2hex(random_bytes(8))));
        $this->comandos[] = $envelope;

        $this->broker->canal()->basic_publish(
            new AMQPMessage($envelope->toJson(), [
                'content_type'        => 'application/json',
                'delivery_mode'       => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(['traceparent' => $envelope->traceparent]),
            ]),
            '',
            $fila
        );

        $tratadas = $this->app->make(ConsumidorDeComandos::class)->executar(1, self::ESPERA_SEGUNDOS);
        $this->garantir($tratadas === 1, "O worker não tratou o comando {$tipo}.");
    }

    /** @return array<int, array{routingKey: string, envelope: Envelope}> */
    private function esperarEventosDaOs(string $os, int $quantos): array
    {
        $limite = microtime(true) + self::ESPERA_SEGUNDOS;

        do {
            $daOs = array_values(array_filter($this->recebidos, fn ($r) => ($r['envelope']->body['osId'] ?? null) === $this->id($os)));
            if (count($daOs) >= $quantos) {
                return $daOs;
            }
        } while ($this->lerProximoEvento() || microtime(true) < $limite);

        return $daOs;
    }

    /** O primeiro evento com essa routing key para a OS, esperando até ESPERA_SEGUNDOS; null se não chegar. */
    private function esperarEvento(string $routingKey, string $os): ?Envelope
    {
        $limite = microtime(true) + self::ESPERA_SEGUNDOS;

        do {
            foreach ($this->recebidos as ['routingKey' => $chave, 'envelope' => $envelope]) {
                if ($chave === $routingKey && ($envelope->body['osId'] ?? null) === $this->id($os)) {
                    return $envelope;
                }
            }
        } while ($this->lerProximoEvento() || microtime(true) < $limite);

        return null;
    }

    /** Lê um evento da fila do orquestrador, se houver; espera um pouco quando ela está vazia. */
    private function lerProximoEvento(): bool
    {
        $mensagem = $this->broker->canal()->basic_get(self::FILA_ORQUESTRADOR, true);

        if ($mensagem === null) {
            usleep(50_000);

            return false;
        }

        $this->recebidos[] = ['routingKey' => $mensagem->getRoutingKey(), 'envelope' => Envelope::fromJson($mensagem->getBody())];

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    private function timeline(string $os): array
    {
        [$status, $eventos] = $this->http('GET', "/api/execucao/os/{$this->id($os)}/timeline");
        $this->garantir($status === 200, "A OS {$os} não tem timeline (HTTP {$status}).");

        return $eventos;
    }

    /** @return array{0: int, 1: mixed} status HTTP e corpo JSON decodificado */
    private function http(string $metodo, string $uri, array $dados = []): array
    {
        $requisicao = Request::create($uri, $metodo, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ], $dados === [] ? null : json_encode($dados));

        $kernel = $this->app->make(HttpKernel::class);
        $resposta = $kernel->handle($requisicao);
        $kernel->terminate($requisicao, $resposta);

        return [$resposta->getStatusCode(), json_decode((string) $resposta->getContent(), true)];
    }

    private function id(string $nome): string
    {
        return $this->ids[$nome] ??= (string) Str::uuid();
    }

    private function traceIdDe(Envelope $envelope): ?string
    {
        return $envelope->traceparent !== null ? (explode('-', $envelope->traceparent)[1] ?? null) : null;
    }

    private function contarMensagens(string $fila): int
    {
        [, $mensagens] = $this->broker->canal()->queue_declare($fila, true);

        return (int) $mensagens;
    }

    private function garantir(bool $condicao, string $mensagem): void
    {
        if (!$condicao) {
            throw new \RuntimeException($mensagem);
        }
    }

    /** A mesma forma da topologia do contrato (docker/rabbitmq/definitions.json), com nomes .bdd. */
    private function declararTopologia(): void
    {
        $canal = $this->broker->canal();

        $canal->exchange_declare(self::EXCHANGE_EVENTOS, 'topic', false, true, false);
        $canal->exchange_declare(self::EXCHANGE_DLX, 'direct', false, true, false);

        foreach ([self::FILA_ENVIAR => self::DLQS[0], self::FILA_CANCELAR => self::DLQS[1]] as $fila => $dlq) {
            $canal->queue_declare($dlq, false, true, false, false);
            $canal->queue_bind($dlq, self::EXCHANGE_DLX, $dlq);
            $canal->queue_declare($fila, false, true, false, false, false, new AMQPTable([
                'x-dead-letter-exchange'    => self::EXCHANGE_DLX,
                'x-dead-letter-routing-key' => $dlq,
            ]));
        }

        $canal->queue_declare(self::FILA_ORQUESTRADOR, false, true, false, false);
        $canal->queue_bind(self::FILA_ORQUESTRADOR, self::EXCHANGE_EVENTOS, 'execucao.*');

        foreach ([...self::DLQS, self::FILA_ENVIAR, self::FILA_CANCELAR, self::FILA_ORQUESTRADOR] as $fila) {
            $canal->queue_purge($fila);
        }
    }

    private function limparBancos(): void
    {
        $chaves = Redis::keys('execucao:*');
        if ($chaves !== []) {
            Redis::del($chaves);
        }

        $mongo = $this->app->make(Database::class);
        $mongo->selectCollection(MongoTimelineRepository::COLECAO)->drop();
        $mongo->selectCollection(MongoMensagensProcessadasRepository::COLECAO)->drop();
    }
}
