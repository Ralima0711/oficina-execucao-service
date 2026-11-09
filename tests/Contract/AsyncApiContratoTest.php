<?php

namespace Tests\Contract;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MensagemInvalida;
use App\Application\Services\CancelarExecucaoHandler;
use App\Application\Services\EnviarParaExecucaoHandler;
use App\Application\Services\EventosDeExecucao;
use App\Application\Services\ExecucaoService;
use App\Application\Services\FilaExecucaoService;
use App\Domain\Execucao\Entities\EventoTimeline;
use App\Infrastructure\Messaging\RabbitMq\ConexaoRabbitMq;
use App\Infrastructure\Messaging\RabbitMq\ConsumidorDeComandos;
use App\Domain\Execucao\ValueObjects\ContextoSaga;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContratoAsyncApi;
use Tests\Support\FilaEmMemoria;
use Tests\Support\Ids;
use Tests\Support\MensagensProcessadasEmMemoria;
use Tests\Support\PublicadorEmMemoria;
use Tests\Support\TimelineEmMemoria;
use Tests\TestCase;

/**
 * Teste de contrato: o que a Execução consome e publica, validado contra o
 * docs/asyncapi.yaml (a fonte compartilhada com o OS Service e o Billing) e contra o
 * contrato de eventos v1.1.
 *
 *  - consumo:   tudo que o contrato PERMITE num comando, o consumidor aceita
 *               (recusar um comando válido deixa a saga esperando para sempre);
 *  - publicação: todo evento que a Execução publica cumpre o contrato;
 *  - nomes:     filas, exchange e routing keys são as do contrato;
 *  - cobertura: a Execução implementa tudo que o contrato lhe atribui; o teste falha se o
 *               contrato ganhar algo novo para ela, para ninguém esquecer de revisar.
 */
class AsyncApiContratoTest extends TestCase
{
    private const SAGA_ID = '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f';

    private ContratoAsyncApi $contrato;

    private FilaEmMemoria $fila;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contrato = ContratoAsyncApi::carregar();
        $this->fila = new FilaEmMemoria();
    }

    // ── versão do contrato ────────────────────────────────────────

    public function test_o_contrato_implementado_e_a_versao_1_1(): void
    {
        $this->assertSame(
            '1.1.0',
            $this->contrato->versao(),
            'O AsyncAPI mudou de versão. Revise o que a Execução consome e publica e só então atualize este teste.'
        );
        $this->assertStringContainsString('v1.1', $this->texto('docs/contrato-eventos-fase4.md'), 'O contrato de eventos mudou de versão.');
    }

    // ── consumo: EnviarParaExecucao ───────────────────────────────

    /** @return array<string, array{0: array<string, mixed>}> comandos que o contrato diz serem válidos */
    public static function comandosValidosSegundoOContrato(): array
    {
        $os = Ids::os(10);
        $orcamento = Ids::os(99);

        return [
            'só os campos obrigatórios' => [['osId' => $os, 'orcamentoId' => $orcamento]],
            'com prioridade 1'          => [['osId' => $os, 'orcamentoId' => $orcamento, 'prioridade' => 1]],
            'com prioridade 5'          => [['osId' => $os, 'orcamentoId' => $orcamento, 'prioridade' => 5]],
            'com itens'                 => [['osId' => $os, 'orcamentoId' => $orcamento, 'itens' => [
                ['descricao' => 'Pastilha de freio', 'tipo' => 'peca', 'quantidade' => 2],
                ['descricao' => 'Alinhamento', 'tipo' => 'servico', 'quantidade' => 1],
            ]]],
            'itens vazio'               => [['osId' => $os, 'orcamentoId' => $orcamento, 'itens' => []]],
            'campo que o contrato não conhece' => [['osId' => $os, 'orcamentoId' => $orcamento, 'novoCampo' => 'x']],
            'UUID em maiúsculas'        => [['osId' => strtoupper($os), 'orcamentoId' => strtoupper($orcamento)]],
        ];
    }

    #[DataProvider('comandosValidosSegundoOContrato')]
    public function test_o_contrato_considera_valido_o_comando_de_exemplo(array $corpo): void
    {
        $this->assertSame([], $this->contrato->validarEnvelope('EnviarParaExecucao', $this->comando($corpo)->toJson()));
    }

    #[DataProvider('comandosValidosSegundoOContrato')]
    public function test_o_consumidor_aceita_todo_comando_valido_segundo_o_contrato(array $corpo): void
    {
        $envelope = $this->comando($corpo);

        // Falha o teste se o comando de exemplo deixar de ser válido no contrato (o exemplo envelheceu).
        $this->assertSame([], $this->contrato->validarEnvelope('EnviarParaExecucao', $envelope->toJson()));

        $this->handler()->handle($envelope);

        $this->assertCount(1, $this->fila->listar(), 'O comando é válido no contrato e a OS não entrou na fila.');
        $this->assertSame(mb_strtolower($corpo['osId']), mb_strtolower($this->fila->listar()[0]->osId));
    }

    /**
     * Desde a v1.1 o schema limita a prioridade a 1..5, mas a regra 6 da Parte 5 manda o participante
     * ajustar o campo acessório e executar: recusar mandaria a OS para a DLQ e a saga esperaria para sempre.
     * Por isso estes comandos são inválidos no contrato e, ainda assim, o consumidor os aceita.
     */
    public function test_prioridade_fora_da_faixa_e_ajustada_em_vez_de_recusada(): void
    {
        foreach ([-3 => 1, 0 => 1, 6 => 5, 10 => 5, 1_000_000 => 5] as $recebida => $esperada) {
            $fila = new FilaEmMemoria();
            $this->handler($fila)->handle($this->comando(['osId' => Ids::os(10), 'orcamentoId' => Ids::os(99), 'prioridade' => $recebida]));

            $this->assertSame($esperada, $fila->listar()[0]->prioridade, "prioridade {$recebida}");
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> o que o contrato diz ser inválido */
    public static function comandosInvalidosSegundoOContrato(): array
    {
        $os = Ids::os(10);
        $orcamento = Ids::os(99);

        return [
            'osId ausente'          => [['orcamentoId' => $orcamento]],
            'orcamentoId ausente'   => [['osId' => $os]],
            'osId que não é UUID'   => [['osId' => '123', 'orcamentoId' => $orcamento]],
            'osId numérico'         => [['osId' => 123, 'orcamentoId' => $orcamento]],
            'prioridade como texto' => [['osId' => $os, 'orcamentoId' => $orcamento, 'prioridade' => 'alta']],
            'prioridade decimal'    => [['osId' => $os, 'orcamentoId' => $orcamento, 'prioridade' => 1.5]],
            'prioridade 0'          => [['osId' => $os, 'orcamentoId' => $orcamento, 'prioridade' => 0]],
            'prioridade 6'          => [['osId' => $os, 'orcamentoId' => $orcamento, 'prioridade' => 6]],
            'prioridade negativa'   => [['osId' => $os, 'orcamentoId' => $orcamento, 'prioridade' => -3]],
            'prioridade enorme'     => [['osId' => $os, 'orcamentoId' => $orcamento, 'prioridade' => 1_000_000]],
            'item sem descricao'    => [['osId' => $os, 'orcamentoId' => $orcamento, 'itens' => [['tipo' => 'peca', 'quantidade' => 1]]]],
            'item com tipo errado'  => [['osId' => $os, 'orcamentoId' => $orcamento, 'itens' => [['descricao' => 'x', 'tipo' => 'brinde', 'quantidade' => 1]]]],
            'item com quantidade 0' => [['osId' => $os, 'orcamentoId' => $orcamento, 'itens' => [['descricao' => 'x', 'tipo' => 'peca', 'quantidade' => 0]]]],
        ];
    }

    /** Garante que a validação não é vazia: o contrato de fato recusa estes comandos. */
    #[DataProvider('comandosInvalidosSegundoOContrato')]
    public function test_o_contrato_recusa_o_comando_invalido(array $corpo): void
    {
        $this->assertNotSame([], $this->contrato->validarEnvelope('EnviarParaExecucao', $this->comando($corpo)->toJson()));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}> inválidos no contrato e que o consumidor também recusa
     *                                                      (a prioridade fora da faixa fica de fora: é ajustada, regra 6)
     */
    public static function comandosQueOConsumidorTambemRecusa(): array
    {
        return array_intersect_key(self::comandosInvalidosSegundoOContrato(), array_flip([
            'osId ausente', 'orcamentoId ausente', 'osId que não é UUID', 'osId numérico', 'prioridade como texto', 'prioridade decimal',
        ]));
    }

    /**
     * Os campos que o consumidor valida e que o contrato também exige não podem sumir.
     * (Os itens ficam de fora de propósito: o consumidor não os usa e os tolera.)
     */
    #[DataProvider('comandosQueOConsumidorTambemRecusa')]
    public function test_o_consumidor_recusa_o_que_o_contrato_recusa_nos_campos_que_usa(array $corpo): void
    {
        $this->expectException(MensagemInvalida::class);

        $this->handler()->handle($this->comando($corpo));
    }

    /** @return array<string, array{0: string}> */
    public static function camposDoHeader(): array
    {
        return array_combine(
            ['messageId', 'sagaId', 'type', 'occurredAt', 'version'],
            array_map(fn ($c) => [$c], ['messageId', 'sagaId', 'type', 'occurredAt', 'version'])
        );
    }

    #[DataProvider('camposDoHeader')]
    public function test_o_consumidor_exige_cada_campo_do_header_que_o_contrato_exige(string $campo): void
    {
        $this->assertContains($campo, $this->contrato->headersObrigatorios(), 'O contrato deixou de exigir este campo do header.');

        $envelope = json_decode($this->comando(['osId' => Ids::os(10), 'orcamentoId' => Ids::os(99)])->toJson(), true);
        unset($envelope['header'][$campo]);

        $this->expectException(MensagemInvalida::class);

        Envelope::fromJson(json_encode($envelope));
    }

    public function test_todos_os_campos_obrigatorios_do_header_sao_conhecidos_pela_execucao(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(self::camposDoHeader()),
            $this->contrato->headersObrigatorios(),
            'O contrato passou a exigir um campo de header novo; a Execução precisa validá-lo e publicá-lo.'
        );
    }

    // ── consumo: CancelarExecucao (compensação) ───────────────────

    /** @return array<string, array{0: array<string, mixed>}> comandos que o contrato diz serem válidos */
    public static function cancelamentosValidosSegundoOContrato(): array
    {
        $os = Ids::os(10);

        return [
            'só os campos obrigatórios'  => [['osId' => $os, 'motivo' => 'execucao_falhou']],
            'com orcamentoId'            => [['osId' => $os, 'orcamentoId' => Ids::os(99), 'motivo' => 'pagamento_recusado']],
            'com pagamentoId'            => [['osId' => $os, 'orcamentoId' => Ids::os(99), 'pagamentoId' => Ids::os(77), 'motivo' => 'execucao_falhou']],
            'orcamento_falhou'           => [['osId' => $os, 'motivo' => 'orcamento_falhou']],
            'orcamento_recusado'         => [['osId' => $os, 'motivo' => 'orcamento_recusado']],
            'aprovacao_expirada'         => [['osId' => $os, 'motivo' => 'aprovacao_expirada']],
            'campo que o contrato não conhece' => [['osId' => $os, 'motivo' => 'execucao_falhou', 'novoCampo' => 'x']],
        ];
    }

    #[DataProvider('cancelamentosValidosSegundoOContrato')]
    public function test_o_consumidor_aceita_todo_cancelamento_valido_segundo_o_contrato(array $corpo): void
    {
        $envelope = Envelope::novo('CancelarExecucao', self::SAGA_ID, null, $corpo, '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

        // Falha o teste se o comando de exemplo deixar de ser válido no contrato (o exemplo envelheceu).
        $this->assertSame([], $this->contrato->validarEnvelope('Compensacao', $envelope->toJson()));

        $this->cancelador()->handle($envelope);

        $this->addToAssertionCount(1); // aceitar = não lançar: recusar mandaria a compensação para a DLQ
    }

    /** @return array<string, array{0: array<string, mixed>}> o que o contrato diz ser inválido e o consumidor também recusa */
    public static function cancelamentosInvalidos(): array
    {
        $os = Ids::os(10);

        return [
            'osId ausente'        => [['motivo' => 'execucao_falhou']],
            'motivo ausente'      => [['osId' => $os]],
            'osId que não é UUID' => [['osId' => '123', 'motivo' => 'execucao_falhou']],
            'orcamentoId inválido' => [['osId' => $os, 'orcamentoId' => 'x', 'motivo' => 'execucao_falhou']],
        ];
    }

    #[DataProvider('cancelamentosInvalidos')]
    public function test_o_consumidor_recusa_o_que_o_contrato_recusa_no_cancelamento(array $corpo): void
    {
        $envelope = Envelope::novo('CancelarExecucao', self::SAGA_ID, null, $corpo, null);

        $this->assertNotSame([], $this->contrato->validarEnvelope('Compensacao', $envelope->toJson()));

        $this->expectException(MensagemInvalida::class);

        $this->cancelador()->handle($envelope);
    }

    /**
     * O contrato (Parte 4) diz que o motivo de cancelamento é um dos de MotivoCompensacao; o consumidor
     * aceita qualquer texto de propósito. Este teste só trava a lista que a Execução conhece.
     */
    public function test_os_motivos_de_compensacao_do_contrato_sao_os_que_a_execucao_conhece(): void
    {
        $motivos = array_map(fn ($c) => $c[0]['motivo'], self::cancelamentosValidosSegundoOContrato());

        foreach (['orcamento_falhou', 'orcamento_recusado', 'aprovacao_expirada', 'pagamento_recusado', 'execucao_falhou'] as $motivo) {
            $this->assertContains($motivo, $motivos);
        }
    }

    // ── publicação: execucao.iniciada / execucao.concluida / execucao.falhou ──

    public function test_os_eventos_que_a_execucao_publica_cumprem_o_contrato(): void
    {
        $publicador = new PublicadorEmMemoria();
        $eventos = new EventosDeExecucao($publicador);
        $saga = new ContextoSaga(self::SAGA_ID, '7c9e6679-7425-40de-944b-e07fc1f90ae7', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

        $eventos->iniciada($this->evento(TipoEvento::EXECUCAO_INICIADA, '14:00:00', $saga));
        $eventos->concluida($this->evento(TipoEvento::EXECUCAO_FINALIZADA, '16:30:00', $saga), new \DateTimeImmutable('2026-11-02 14:00:00', new \DateTimeZone('UTC')));
        $eventos->falhou($this->evento(TipoEvento::EXECUCAO_FALHOU, '16:40:00', $saga), 'Peça com defeito', new \DateTimeImmutable('2026-11-02 14:00:00', new \DateTimeZone('UTC')));
        $eventos->falhou($this->evento(TipoEvento::EXECUCAO_FALHOU, '16:50:00', $saga, semMecanico: true), 'cancelada');

        $this->assertSame(['execucao.iniciada', 'execucao.concluida', 'execucao.falhou', 'execucao.falhou'], $publicador->routingKeys());

        foreach ($publicador->publicadas as ['routingKey' => $chave, 'envelope' => $envelope]) {
            $this->assertSame(
                [],
                $this->contrato->validarEnvelope('ExecucaoAtualizada', $envelope->toJson()),
                "{$chave} não cumpre o contrato (ExecucaoAtualizada)"
            );
        }
    }

    public function test_o_validador_recusa_um_evento_que_nao_cumpre_o_contrato(): void
    {
        $semExecucaoId = Envelope::novo('execucao.iniciada', self::SAGA_ID, null, ['osId' => Ids::os(10)], null);
        $osInvalida = Envelope::novo('execucao.iniciada', self::SAGA_ID, null, ['osId' => '123', 'execucaoId' => Ids::os(1)], null);

        $this->assertNotSame([], $this->contrato->validarEnvelope('ExecucaoAtualizada', $semExecucaoId->toJson()));
        $this->assertNotSame([], $this->contrato->validarEnvelope('ExecucaoAtualizada', $osInvalida->toJson()));
    }

    // ── nomes: filas, exchange e routing keys ─────────────────────

    public function test_a_fila_de_comando_e_o_exchange_de_eventos_sao_os_do_contrato(): void
    {
        $this->assertSame($this->contrato->enderecoDoCanal('enviarParaExecucao'), config('rabbitmq.fila_enviar'));
        $this->assertSame($this->contrato->enderecoDoCanal('eventosDeDominio'), config('rabbitmq.exchange_eventos'));
    }

    public function test_a_topologia_do_compose_declara_as_filas_do_contrato_com_dlq(): void
    {
        $definicoes = json_decode($this->texto('docker/rabbitmq/definitions.json'), true);
        $filas = array_column($definicoes['queues'], null, 'name');

        foreach (['enviarParaExecucao', 'cancelarExecucao'] as $canal) {
            $fila = $this->contrato->enderecoDoCanal($canal);

            $this->assertArrayHasKey($fila, $filas, "A fila {$fila} do contrato não está na topologia.");
            $this->assertSame('oficina.dlx', $filas[$fila]['arguments']['x-dead-letter-exchange']);
            $this->assertArrayHasKey("dlq.{$fila}", $filas);
        }

        $exchanges = array_column($definicoes['exchanges'], 'type', 'name');
        $this->assertSame('topic', $exchanges[$this->contrato->enderecoDoCanal('eventosDeDominio')]);
    }

    public function test_as_routing_keys_publicadas_sao_as_que_o_contrato_atribui_a_execucao(): void
    {
        $publicadas = ['execucao.iniciada', 'execucao.concluida', 'execucao.falhou'];

        foreach ($publicadas as $chave) {
            $this->assertContains($chave, $this->routingKeysDaExecucaoNoContrato());
        }
    }

    // ── cobertura do contrato ─────────────────────────────────────

    /**
     * Sem lacunas: a Execução publica todas as routing keys que o contrato lhe atribui e consome as
     * duas filas de comando. Se o contrato ganhar mensagem nova para a Execução, este teste quebra.
     */
    public function test_a_execucao_cobre_tudo_que_o_contrato_lhe_atribui(): void
    {
        $implementadas = ['execucao.iniciada', 'execucao.concluida', 'execucao.falhou'];

        $this->assertEqualsCanonicalizing(
            $implementadas,
            $this->routingKeysDaExecucaoNoContrato(),
            'Mudou o que a Execução publica ou o que o contrato prevê. Revise e atualize o README.'
        );

        $filasDeComandoDaExecucao = [
            $this->contrato->enderecoDoCanal('enviarParaExecucao'),
            $this->contrato->enderecoDoCanal('cancelarExecucao'),
        ];

        $this->assertEqualsCanonicalizing(
            $filasDeComandoDaExecucao,
            array_keys($this->consumidor()->filas()),
            'Mudou o que a Execução consome. Revise e atualize o README.'
        );
    }

    // ── apoio ─────────────────────────────────────────────────────

    private function consumidor(): ConsumidorDeComandos
    {
        return new ConsumidorDeComandos(new ConexaoRabbitMq(), $this->handler(), $this->cancelador());
    }

    private function comando(array $corpo): Envelope
    {
        return Envelope::novo('EnviarParaExecucao', self::SAGA_ID, null, $corpo, '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
    }

    private function handler(?FilaEmMemoria $fila = null): EnviarParaExecucaoHandler
    {
        $fila ??= $this->fila;
        $servico = new FilaExecucaoService($fila, new TimelineEmMemoria(), new EventosDeExecucao(new PublicadorEmMemoria()));

        return new EnviarParaExecucaoHandler($servico, new MensagensProcessadasEmMemoria());
    }

    private function cancelador(): CancelarExecucaoHandler
    {
        $eventos = new EventosDeExecucao(new PublicadorEmMemoria());

        return new CancelarExecucaoHandler(new ExecucaoService(new TimelineEmMemoria(), $this->fila, $eventos), new MensagensProcessadasEmMemoria());
    }

    private function evento(TipoEvento $tipo, string $hora, ContextoSaga $saga, bool $semMecanico = false): EventoTimeline
    {
        return new EventoTimeline(
            osId: Ids::os(10),
            tipo: $tipo,
            statusAnterior: StatusExecucao::APROVADA,
            statusNovo: StatusExecucao::EM_EXECUCAO,
            mecanicoId: $semMecanico ? null : Ids::mecanico(7),
            duracaoSegundos: null,
            observacao: null,
            traceId: null,
            ocorridoEm: new \DateTimeImmutable("2026-11-02 {$hora}", new \DateTimeZone('UTC')),
            execucaoId: '44444444-4444-4444-8444-444444444444',
            saga: $saga,
        );
    }

    /** @return string[] as routing keys que o contrato v1.1 (Parte 2) atribui à Execução */
    private function routingKeysDaExecucaoNoContrato(): array
    {
        // Só a tabela de eventos ("Routing key | Publicado por"); a de comandos também cita a Execução.
        $contrato = $this->texto('docs/contrato-eventos-fase4.md');
        $tabela = explode('| Routing key | Publicado por |', $contrato, 2)[1] ?? '';
        $tabela = explode("\n\n", $tabela, 2)[0];

        preg_match_all('/^\|\s*`(execucao\.[a-z-]+)`\s*\|\s*Execução\s*\|/mu', $tabela, $achadas);

        return $achadas[1];
    }

    private function texto(string $caminho): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $caminho);
    }
}
