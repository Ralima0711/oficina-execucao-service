<?php

namespace App\Application\Services;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MensagemInvalida;
use App\Application\Messaging\TrataComando;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Repositories\MensagemProcessadaRepositoryInterface;
use App\Domain\Execucao\ValueObjects\ContextoSaga;
use App\Infrastructure\Messaging\TraceContextCarrier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Comando execucao.cancelar-execucao (CancelarExecucao): compensação da saga.
 *
 *  1. valida o corpo (inválido não adianta repetir: vai para a DLQ);
 *  2. descarta o messageId já tratado (idempotência);
 *  3. cancela conforme onde a OS está (ExecucaoService::cancelar): tira da fila, encerra o
 *     reparo, ou não desfaz o que já foi concluído.
 *
 * Quando há o que desfazer, o execucao.falhou sai no mesmo trace do comando, com o sagaId dele e
 * causationId igual ao messageId do CancelarExecucao. Roda dentro do span CONSUMER aberto por
 * MessageTracing::consumir.
 */
class CancelarExecucaoHandler implements TrataComando
{
    public function __construct(
        private ExecucaoService $execucao,
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
            // O traceparent é o do span atual (consumidor): o execucao.falhou fica encadeado a ele.
            $saga = new ContextoSaga(
                $envelope->sagaId,
                $envelope->messageId,
                TraceContextCarrier::inject()[TraceContextCarrier::TRACEPARENT] ?? $envelope->traceparent
            );

            $this->execucao->cancelar($dados['osId'], $dados['motivo'], $saga);
        } catch (ConflitoException $e) {
            // Alguém gravou o fim desta execução ao mesmo tempo: o efeito já aconteceu.
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
     * @return array{osId: string, motivo: string}
     */
    private function validar(array $corpo): array
    {
        $validador = Validator::make($corpo, [
            'osId'        => ['required', 'uuid'],
            // O contrato lista os motivos (MotivoCompensacao), mas o motivo só vai para o log e a
            // timeline: um valor novo não pode mandar a compensação para a DLQ.
            'motivo'      => ['required', 'string', 'max:1000'],
            'orcamentoId' => ['sometimes', 'uuid'],
            'pagamentoId' => ['sometimes', 'uuid'],
        ]);

        if ($validador->fails()) {
            throw new MensagemInvalida('Corpo do comando fora do contrato: ' . $validador->errors()->first());
        }

        return ['osId' => (string) $corpo['osId'], 'motivo' => (string) $corpo['motivo']];
    }
}
