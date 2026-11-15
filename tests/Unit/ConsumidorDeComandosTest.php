<?php

namespace Tests\Unit;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MensagemInvalida;
use App\Application\Messaging\TrataComando;
use App\Application\Services\CancelarExecucaoHandler;
use App\Application\Services\EnviarParaExecucaoHandler;
use App\Infrastructure\Messaging\RabbitMq\ConexaoRabbitMq;
use App\Infrastructure\Messaging\RabbitMq\ConsumidorDeComandos;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\Support\Ids;
use Tests\TestCase;

/**
 * A decisão de ack/nack do consumidor, sem broker: o canal do AMQP é um dublê que
 * registra o que o consumidor respondeu para cada mensagem.
 */
class ConsumidorDeComandosTest extends TestCase
{
    use UsaTracerEmMemoria;

    private const FILA = 'execucao.enviar-para-execucao';
    private const SAGA_ID = '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f';
    private const TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
    private const DELIVERY_TAG = 42;

    private ConsumidorDeComandos $consumidor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usarTracerEmMemoria();

        $this->consumidor = new ConsumidorDeComandos(
            new ConexaoRabbitMq(),
            $this->createMock(EnviarParaExecucaoHandler::class),
            $this->createMock(CancelarExecucaoHandler::class),
        );
    }

    protected function tearDown(): void
    {
        $this->restaurarTracer();
        parent::tearDown();
    }

    public function test_mensagem_sem_headers_amqp_e_tratada_e_confirmada(): void
    {
        $envelope = $this->envelope();
        $canal = $this->canalQueEspera(ack: 1, nack: 0);
        $handler = new class implements TrataComando {
            public ?Envelope $recebido = null;

            public function handle(Envelope $envelope): void
            {
                $this->recebido = $envelope;
            }
        };

        // Sem application_headers: o traceparent vem só do corpo do envelope.
        $this->consumidor->processar($this->mensagem($envelope->toJson(), $canal), self::FILA, $handler);

        $this->assertNotNull($handler->recebido);
        $this->assertSame($envelope->messageId, $handler->recebido->messageId);
        $this->assertSame(self::SAGA_ID, $handler->recebido->sagaId);
        $this->assertSame(self::TRACEPARENT, $handler->recebido->traceparent);
    }

    public function test_comando_recusado_pelo_handler_vai_para_a_dlq_sem_requeue(): void
    {
        $canal = $this->canalQueEspera(ack: 0, nack: 1);
        $handler = new class implements TrataComando {
            public function handle(Envelope $envelope): void
            {
                throw new MensagemInvalida('body.osId deve ser um UUID.');
            }
        };

        $this->consumidor->processar($this->mensagem($this->envelope()->toJson(), $canal), self::FILA, $handler);

        $consumo = $this->spansExportados()[0];
        $this->assertSame(self::FILA . ' process', $consumo->getName());
        $this->assertSame(self::SAGA_ID, $consumo->getAttributes()->get('saga.id'));
    }

    public function test_falha_inesperada_do_handler_tambem_vai_para_a_dlq(): void
    {
        $canal = $this->canalQueEspera(ack: 0, nack: 1);
        $handler = new class implements TrataComando {
            public function handle(Envelope $envelope): void
            {
                throw new \RuntimeException('Redis fora do ar');
            }
        };

        $this->consumidor->processar($this->mensagem($this->envelope()->toJson(), $canal), self::FILA, $handler);
    }

    public function test_corpo_fora_do_contrato_vai_para_a_dlq_sem_chamar_o_handler(): void
    {
        $canal = $this->canalQueEspera(ack: 0, nack: 1);
        $handler = new class implements TrataComando {
            public bool $chamado = false;

            public function handle(Envelope $envelope): void
            {
                $this->chamado = true;
            }
        };

        $this->consumidor->processar($this->mensagem('{"header": {}}', $canal), self::FILA, $handler);

        $this->assertFalse($handler->chamado);
        $this->assertSame([], $this->spansExportados(), 'Sem envelope válido não há trace para continuar.');
    }

    public function test_filas_ligam_cada_fila_de_comando_ao_seu_handler(): void
    {
        config(['rabbitmq.fila_enviar' => 'fila.enviar', 'rabbitmq.fila_cancelar' => 'fila.cancelar']);

        $filas = $this->consumidor->filas();

        $this->assertSame(['fila.enviar', 'fila.cancelar'], array_keys($filas));
        $this->assertInstanceOf(EnviarParaExecucaoHandler::class, $filas['fila.enviar']);
        $this->assertInstanceOf(CancelarExecucaoHandler::class, $filas['fila.cancelar']);
    }

    // ── apoio ─────────────────────────────────────────────────────

    private function envelope(): Envelope
    {
        return Envelope::novo(
            'EnviarParaExecucao',
            self::SAGA_ID,
            null,
            ['osId' => Ids::os(10), 'orcamentoId' => Ids::os(99)],
            self::TRACEPARENT
        );
    }

    /** Canal que exige exatamente `ack` confirmações e `nack` rejeições, sempre sem requeue. */
    private function canalQueEspera(int $ack, int $nack): AMQPChannel&MockObject
    {
        $canal = $this->createMock(AMQPChannel::class);
        $canal->expects($this->exactly($ack))->method('basic_ack')->with(self::DELIVERY_TAG, false);
        $canal->expects($this->exactly($nack))->method('basic_nack')->with(self::DELIVERY_TAG, false, false);

        return $canal;
    }

    private function mensagem(string $corpo, AMQPChannel $canal): AMQPMessage
    {
        $mensagem = new AMQPMessage($corpo, ['content_type' => 'application/json']);
        $mensagem->setChannel($canal);
        $mensagem->setDeliveryTag(self::DELIVERY_TAG);

        return $mensagem;
    }
}
