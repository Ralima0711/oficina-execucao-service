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


class FilaExecucaoService
{
    public function __construct(
        private FilaExecucaoRepositoryInterface $fila,
        private TimelineRepositoryInterface $timeline,
    ) {}

    public function enfileirar(int $osId): ItemFila
    {
        return Tracing::executar('FilaExecucaoService::enfileirar', ['os.id' => $osId], function () use ($osId) {
            $status = $this->timeline->ultimoEventoDeEstado($osId)?->statusNovo;

            if ($status === StatusExecucao::EM_EXECUCAO || $status === StatusExecucao::FINALIZADA) {
                throw new ConflitoException("A OS #{$osId} já está {$status->value} e não pode voltar para a fila.");
            }

            $item = $this->fila->enfileirar($osId, CarbonImmutable::now('UTC')->toDateTimeImmutable());
            $item = $item->comPosicao($this->fila->posicao($osId));

            Log::info('os_enfileirada', ['os.id' => $osId, 'fila.posicao' => $item->posicao]);

            return $item;
        });
    }

    /**
     * Mecânico puxa a próxima OS: ela sai da fila, o mecânico fica ocupado com ela
     * e a timeline ganha EXECUCAO_INICIADA. Devolve null com a fila vazia.
     */
    public function proxima(int $mecanicoId): ?EventoTimeline
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

    public function remover(int $osId): void
    {
        Tracing::executar('FilaExecucaoService::remover', ['os.id' => $osId], function () use ($osId) {
            if (!$this->fila->remover($osId)) {
                throw new NaoEncontradoException("A OS #{$osId} não está na fila de execução.");
            }

            Log::info('os_removida_da_fila', ['os.id' => $osId]);
        });
    }
}
