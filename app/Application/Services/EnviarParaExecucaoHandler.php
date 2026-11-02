<?php

namespace App\Application\Services;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MensagemInvalida;
use App\Domain\Execucao\Entities\ItemFila;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Repositories\MensagemProcessadaRepositoryInterface;
use App\Domain\Execucao\ValueObjects\ContextoSaga;
use App\Infrastructure\Messaging\TraceContextCarrier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Comando execucao.enviar-para-execucao: o orquestrador manda a OS (já aprovada e
 * paga) para a fila de execução.
 *
 *  1. valida o corpo (inválido não adianta repetir: vai para a DLQ);
 *  2. descarta o messageId já tratado (idempotência);
 *  3. enfileira a OS guardando o contexto da saga e do trace.
 *
 * Roda dentro do span CONSUMER aberto por MessageTracing::consumir.
 */
class EnviarParaExecucaoHandler
{
    public function __construct(
        private FilaExecucaoService $fila,
        private MensagemProcessadaRepositoryInterface $mensagens,
    ) {}

    /**
     * @throws MensagemInvalida corpo fora do contrato
     */
    public function handle(Envelope $envelope): void
    {
        $dados = $this->validar($envelope->body);

        if (!$this->mensagens->marcarSeNova($envelope->messageId)) {
            Log::info('mensagem_duplicada_descartada', [
                'message.id' => $envelope->messageId,
                'saga.id'    => $envelope->sagaId,
                'os.id'      => $dados['osId'],
            ]);

            return;
        }

        try {
            // O traceparent guardado é o do span atual (consumidor): os eventos que
            // a Execução publicar depois ficam encadeados a ele, no mesmo trace.
            $saga = new ContextoSaga(
                $envelope->sagaId,
                $envelope->messageId,
                TraceContextCarrier::inject()[TraceContextCarrier::TRACEPARENT] ?? $envelope->traceparent
            );

            $this->fila->enfileirar($dados['osId'], $dados['prioridade'], $saga);
        } catch (ConflitoException $e) {
            // A OS já está na fila ou já foi executada (o comando voltou com outro messageId).
            // Não há o que desfazer nem o que repetir.
            Log::warning('comando_ignorado', ['motivo' => $e->getMessage(), 'saga.id' => $envelope->sagaId, 'os.id' => $dados['osId']]);
        } catch (\Throwable $e) {
            // Falha de infraestrutura: libera o messageId para o retry não ser descartado como repetição.
            $this->mensagens->liberar($envelope->messageId);

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $corpo
     *
     * @return array{osId: string, orcamentoId: string, prioridade: int}
     */
    private function validar(array $corpo): array
    {
        $validador = Validator::make($corpo, [
            'osId'        => ['required', 'uuid'],
            'orcamentoId' => ['required', 'uuid'],
            'prioridade'  => ['sometimes', 'integer', 'between:' . ItemFila::PRIORIDADE_MINIMA . ',' . ItemFila::PRIORIDADE_MAXIMA],
            'itens'       => ['sometimes', 'array'],
        ]);

        if ($validador->fails()) {
            throw new MensagemInvalida('Corpo do comando fora do contrato: ' . $validador->errors()->first());
        }

        return [
            'osId'        => (string) $corpo['osId'],
            'orcamentoId' => (string) $corpo['orcamentoId'],
            'prioridade'  => (int) ($corpo['prioridade'] ?? ItemFila::PRIORIDADE_MINIMA),
        ];
    }
}
