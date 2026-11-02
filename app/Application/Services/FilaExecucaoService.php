<?php

namespace App\Application\Services;

use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\Entities\ItemFila;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Exceptions\NaoEncontradoException;
use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;
use App\Domain\Execucao\Repositories\TimelineRepositoryInterface;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use App\Infrastructure\Observability\Tracing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


class FilaExecucaoService
{
    public function __construct(
        private FilaExecucaoRepositoryInterface $fila,
        private TimelineRepositoryInterface $timeline,
    ) {}

    public function enfileirar(string $osId, int $prioridade = ItemFila::PRIORIDADE_MINIMA): ItemFila
    {
        return Tracing::executar('FilaExecucaoService::enfileirar', ['os.id' => $osId], function () use ($osId, $prioridade) {
            if ($prioridade < ItemFila::PRIORIDADE_MINIMA || $prioridade > ItemFila::PRIORIDADE_MAXIMA) {
                throw new \DomainException(sprintf(
                    'A prioridade deve estar entre %d e %d.',
                    ItemFila::PRIORIDADE_MINIMA,
                    ItemFila::PRIORIDADE_MAXIMA
                ));
            }

            $status = $this->timeline->ultimoEventoDeEstado($osId)?->statusNovo;

            if ($status === StatusExecucao::EM_EXECUCAO || $status === StatusExecucao::FINALIZADA) {
                throw new ConflitoException("A OS {$osId} já está {$status->value} e não pode voltar para a fila.");
            }

            // Um execucaoId por OS, desde que ela entra na fila: todo evento da execução (inclusive
            // uma falha antes de o mecânico puxar a OS) carrega o mesmo id.
            $item = $this->fila->enfileirar(
                $osId,
                $prioridade,
                (string) Str::uuid(),
                CarbonImmutable::now('UTC')->toDateTimeImmutable()
            );
            $item = $item->comPosicao($this->fila->posicao($osId));

            Log::info('os_enfileirada', [
                'os.id'           => $osId,
                'execucao.id'     => $item->execucaoId,
                'fila.prioridade' => $item->prioridade,
                'fila.posicao'    => $item->posicao,
            ]);

            return $item;
        });
    }

    /**
     * Mecânico puxa a próxima OS: ela sai da fila, o mecânico fica ocupado com ela
     * e a timeline ganha EXECUCAO_INICIADA. Devolve null com a fila vazia.
     */
    public function proxima(string $mecanicoId): ?EventoTimeline
    {
        return Tracing::executar('FilaExecucaoService::proxima', ['os.mecanico_id' => $mecanicoId], function () use ($mecanicoId) {
            $item = $this->fila->proxima($mecanicoId);

            if ($item === null) {
                return null;
            }

            $agora = CarbonImmutable::now('UTC')->toDateTimeImmutable();

            $evento = new EventoTimeline(
                osId: $item->osId,
                tipo: TipoEvento::EXECUCAO_INICIADA,
                statusAnterior: StatusExecucao::APROVADA,
                statusNovo: StatusExecucao::EM_EXECUCAO,
                mecanicoId: $mecanicoId,
                // Tempo que a OS esperou na fila (permaneceu em APROVADA).
                duracaoSegundos: max(0, $agora->getTimestamp() - $item->enfileiradaEm->getTimestamp()),
                observacao: null,
                traceId: Tracing::traceIdAtual(),
                ocorridoEm: $agora,
                execucaoId: $item->execucaoId ?? (string) Str::uuid(),
            );

            try {
                $this->timeline->registrar($evento);
            } catch (\Throwable $e) {
                // Sem o evento na timeline a OS não pode sumir da fila: desfaz a retirada.
                $this->fila->devolver($item, $mecanicoId);

                throw $e;
            }

            Log::info('os_transicao_status', [
                'os.id'               => $evento->osId,
                'execucao.id'         => $evento->execucaoId,
                'os.status_anterior'  => $evento->statusAnterior->value,
                'os.status_novo'      => $evento->statusNovo->value,
                'os.duracao_segundos' => $evento->duracaoSegundos,
            ]);

            return $evento;
        });
    }

    /** @return ItemFila[] */
    public function listar(): array
    {
        return $this->fila->listar();
    }

    public function remover(string $osId): void
    {
        Tracing::executar('FilaExecucaoService::remover', ['os.id' => $osId], function () use ($osId) {
            if (!$this->fila->remover($osId)) {
                throw new NaoEncontradoException("A OS {$osId} não está na fila de execução.");
            }

            Log::info('os_removida_da_fila', ['os.id' => $osId]);
        });
    }
}
