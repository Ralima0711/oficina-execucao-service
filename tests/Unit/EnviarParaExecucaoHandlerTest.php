<?php

namespace Tests\Unit;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MensagemInvalida;
use App\Application\Services\EnviarParaExecucaoHandler;
use App\Application\Services\EventosDeExecucao;
use App\Application\Services\FilaExecucaoService;
use App\Infrastructure\Messaging\MessageTracing;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\Support\FilaEmMemoria;
use Tests\Support\Ids;
use Tests\Support\MensagensProcessadasEmMemoria;
use Tests\Support\PublicadorEmMemoria;
use Tests\Support\TimelineEmMemoria;
use Tests\TestCase;

class EnviarParaExecucaoHandlerTest extends TestCase
{
    use UsaTracerEmMemoria;

    private const SAGA_ID = '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f';
    private const TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    private FilaEmMemoria $fila;

    private MensagensProcessadasEmMemoria $mensagens;

    private EnviarParaExecucaoHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usarTracerEmMemoria();

        $this->fila = new FilaEmMemoria();
        $this->mensagens = new MensagensProcessadasEmMemoria();
        $this->handler = $this->novoHandler($this->fila);
    }

    protected function tearDown(): void
    {
        $this->restaurarTracer();
        parent::tearDown();
    }

    public function test_enfileira_a_os_com_prioridade_e_guarda_o_contexto_da_saga(): void
    {
        $envelope = $this->comando(['prioridade' => 4]);

        $this->consumir($envelope);

        $item = $this->fila->listar()[0];
        $this->assertSame(Ids::os(10), $item->osId);
        $this->assertSame(4, $item->prioridade);
        $this->assertNotNull($item->execucaoId);
        $this->assertSame(self::SAGA_ID, $item->saga->sagaId);
        $this->assertSame($envelope->messageId, $item->saga->mensagemId, 'O messageId do comando vira o causationId dos eventos.');
    }

    public function test_o_traceparent_guardado_continua_o_trace_do_comando_a_partir_do_span_do_consumidor(): void
    {
        $this->consumir($this->comando());

        $item = $this->fila->listar()[0];
        $consumidor = $this->spanPorNome('execucao.enviar-para-execucao process');

        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $item->saga->traceId());
        $this->assertSame(
            sprintf('00-%s-%s-01', $consumidor->getTraceId(), $consumidor->getSpanId()),
            $item->saga->traceparent,
            'O traceparent guardado aponta para o span do consumidor, para os eventos ficarem encadeados a ele.'
        );
    }

    public function test_prioridade_padrao_e_1(): void
    {
        $this->consumir($this->comando());

        $this->assertSame(1, $this->fila->listar()[0]->prioridade);
    }

    public function test_prioridade_fora_da_faixa_e_ajustada_e_nao_recusada(): void
    {
        $esperadas = [-3 => 1, 0 => 1, 1 => 1, 5 => 5, 6 => 5, 1_000_000 => 5];

        foreach ($esperadas as $recebida => $aplicada) {
            $fila = new FilaEmMemoria();
            $this->consumirCom($this->novoHandler($fila), $this->comando(['prioridade' => $recebida]));

            $this->assertSame($aplicada, $fila->listar()[0]->prioridade, "prioridade {$recebida}");
        }
    }

    public function test_mensagem_repetida_com_o_mesmo_message_id_e_descartada(): void
    {
        $envelope = $this->comando();

        $this->consumir($envelope);
        $this->consumir($envelope);

        $this->assertCount(1, $this->fila->listar());
    }

    public function test_comando_para_os_ja_enfileirada_com_outro_message_id_e_ignorado_sem_erro(): void
    {
        $this->consumir($this->comando());
        $this->consumir($this->comando());

        $this->assertCount(1, $this->fila->listar());
    }

    #[DataProvider('corposInvalidos')]
    public function test_corpo_fora_do_contrato_e_recusado_sem_marcar_a_mensagem(array $corpo, string $campo): void
    {
        $envelope = $this->comando([], $corpo);

        try {
            $this->consumir($envelope);
            $this->fail('Deveria recusar o corpo inválido.');
        } catch (MensagemInvalida $e) {
            $this->assertStringContainsString($campo, $e->getMessage());
        }

        $this->assertSame([], $this->fila->listar());
        $this->assertTrue($this->mensagens->marcarSeNova($envelope->messageId), 'Mensagem inválida não pode ficar registrada como tratada.');
    }

    public static function corposInvalidos(): array
    {
        $ok = ['osId' => Ids::os(10), 'orcamentoId' => Ids::os(99)];

        return [
            'osId ausente'       => [['osId' => null], 'os id'],
            'osId não-uuid'      => [['osId' => '123'], 'os id'],
            'orcamentoId ausente' => [['orcamentoId' => null], 'orcamento id'],
            'prioridade texto'   => [['prioridade' => 'alta'], 'prioridade'],
            'itens não é lista'  => [['itens' => 'x'], 'itens'],
        ];
    }

    public function test_falha_de_infraestrutura_libera_o_message_id_para_o_retry(): void
    {
        $fila = new class extends FilaEmMemoria {
            public bool $falhar = true;

            public function enfileirar(string $osId, int $prioridade, string $execucaoId, \DateTimeImmutable $em, ?\App\Domain\Execucao\ValueObjects\ContextoSaga $saga = null): \App\Domain\Execucao\Entities\ItemFila
            {
                if ($this->falhar) {
                    throw new \RuntimeException('redis fora do ar');
                }

                return parent::enfileirar($osId, $prioridade, $execucaoId, $em, $saga);
            }
        };
        $handler = $this->novoHandler($fila);
        $envelope = $this->comando();

        try {
            $this->consumirCom($handler, $envelope);
            $this->fail('A falha deveria ser repropagada (a mensagem vai para a DLQ).');
        } catch (\RuntimeException $e) {
            $this->assertSame('redis fora do ar', $e->getMessage());
        }

        $fila->falhar = false;
        $this->consumirCom($handler, $envelope);

        $this->assertCount(1, $fila->listar(), 'O retry da mesma mensagem não pode ser descartado como repetição.');
    }

    private function comando(array $extraCorpo = [], array $troca = []): Envelope
    {
        $corpo = array_merge(['osId' => Ids::os(10), 'orcamentoId' => Ids::os(99)], $extraCorpo, $troca);

        return Envelope::novo('EnviarParaExecucao', self::SAGA_ID, null, array_filter($corpo, fn ($v) => $v !== null), self::TRACEPARENT);
    }

    private function consumir(Envelope $envelope): void
    {
        $this->consumirCom($this->handler, $envelope);
    }

    /** Como o consumidor faz: o tratamento roda no span CONSUMER filho do traceparent recebido. */
    private function consumirCom(EnviarParaExecucaoHandler $handler, Envelope $envelope): void
    {
        MessageTracing::consumir(
            'execucao.enviar-para-execucao',
            ['traceparent' => $envelope->traceparent],
            fn () => $handler->handle($envelope)
        );
    }

    private function novoHandler(FilaEmMemoria $fila): EnviarParaExecucaoHandler
    {
        $servico = new FilaExecucaoService($fila, new TimelineEmMemoria(), new EventosDeExecucao(new PublicadorEmMemoria()));

        return new EnviarParaExecucaoHandler($servico, $this->mensagens);
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
