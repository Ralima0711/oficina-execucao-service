<?php

namespace App\Application\Services;

use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Exceptions\NaoEncontradoException;
use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;
use App\Domain\Execucao\Repositories\TimelineRepositoryInterface;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use App\Infrastructure\Observability\Tracing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class ExecucaoService
{
    public function __construct(
        private TimelineRepositoryInterface $timeline,
        private FilaExecucaoRepositoryInterface $fila,
    ) {}

    public function iniciarDiagnostico(int $osId, int $mecanicoId, ?string $observacao = null): EventoTimeline
    {
        return Tracing::executar('ExecucaoService::iniciarDiagnostico', ['os.id' => $osId, 'os.mecanico_id' => $mecanicoId], function () use ($osId, $mecanicoId, $observacao) {
            $atual = $this->statusAtual($osId);

            if ($atual !== StatusExecucao::RECEBIDA) {
                throw new \DomainException("Somente ordens RECEBIDAS podem iniciar diagnóstico (status atual: {$atual->value}).");
            }

            return $this->registrarTransicao(
                $osId,
                TipoEvento::DIAGNOSTICO_INICIADO,
                StatusExecucao::RECEBIDA,
                StatusExecucao::EM_DIAGNOSTICO,
                $mecanicoId,
                null,
                $observacao,
            );
        });
    }

    public function registrarNota(int $osId, int $mecanicoId, string $texto): EventoTimeline
    {
        return Tracing::executar('ExecucaoService::registrarNota', ['os.id' => $osId, 'os.mecanico_id' => $mecanicoId], function () use ($osId, $mecanicoId, $texto) {
            $ultimo = $this->timeline->ultimoEventoDeEstado($osId);
            $atual = $ultimo?->statusNovo ?? StatusExecucao::RECEBIDA;

            if ($atual !== StatusExecucao::EM_DIAGNOSTICO && $atual !== StatusExecucao::EM_EXECUCAO) {
                throw new \DomainException("Só é possível anotar durante o diagnóstico ou a execução (status atual: {$atual->value}).");
            }

            $this->exigirMecanicoResponsavel($osId, $ultimo, $mecanicoId);

            $evento = new EventoTimeline(
                osId: $osId,
                tipo: TipoEvento::NOTA,
                statusAnterior: null,
                statusNovo: null,
                mecanicoId: $mecanicoId,
                duracaoSegundos: null,
                observacao: $texto,
                traceId: Tracing::traceIdAtual(),
                ocorridoEm: CarbonImmutable::now('UTC')->toDateTimeImmutable(),
            );

            $this->timeline->registrar($evento);

            return $evento;
        });
    }

    public function finalizar(int $osId, int $mecanicoId, ?string $observacao = null): EventoTimeline
    {
        return Tracing::executar('ExecucaoService::finalizar', ['os.id' => $osId, 'os.mecanico_id' => $mecanicoId], function () use ($osId, $mecanicoId, $observacao) {
            $ultimo = $this->timeline->ultimoEventoDeEstado($osId);
            $atual = $ultimo?->statusNovo ?? StatusExecucao::RECEBIDA;

            if ($atual !== StatusExecucao::EM_EXECUCAO) {
                throw new \DomainException("Somente ordens EM_EXECUCAO podem ser finalizadas (status atual: {$atual->value}).");
            }

            $this->exigirMecanicoResponsavel($osId, $ultimo, $mecanicoId);

            $agora = CarbonImmutable::now('UTC')->toDateTimeImmutable();

            $evento = $this->registrarTransicao(
                $osId,
                TipoEvento::EXECUCAO_FINALIZADA,
                StatusExecucao::EM_EXECUCAO,
                StatusExecucao::FINALIZADA,
                $mecanicoId,
                // Tempo do reparo: desde EXECUCAO_INICIADA.
                max(0, $agora->getTimestamp() - $ultimo->ocorridoEm->getTimestamp()),
                $observacao,
                $agora,
            );

            // Libera o mecânico para puxar a próxima OS. Idempotente.
            $this->fila->concluir($mecanicoId, $osId);

            return $evento;
        });
    }

    /** @return EventoTimeline[] */
    public function timeline(int $osId): array
    {
        $eventos = $this->timeline->porOs($osId);

        if ($eventos === []) {
            throw new NaoEncontradoException("A OS #{$osId} não tem eventos na timeline.");
        }

        return $eventos;
    }

    /** @return array<int, array{status: string, tempoMedioSegundos: float, amostras: int}> */
    public function tempoMedioPorStatus(): array
    {
        return $this->timeline->tempoMedioPorStatus();
    }

    private function statusAtual(int $osId): StatusExecucao
    {
        return $this->timeline->ultimoEventoDeEstado($osId)?->statusNovo ?? StatusExecucao::RECEBIDA;
    }

    private function exigirMecanicoResponsavel(int $osId, ?EventoTimeline $ultimo, int $mecanicoId): void
    {
        if ($ultimo?->mecanicoId !== null && $ultimo->mecanicoId !== $mecanicoId) {
            throw new ConflitoException("A OS #{$osId} está com o mecânico #{$ultimo->mecanicoId}.");
        }
    }

    private function registrarTransicao(
        int $osId,
        TipoEvento $tipo,
        StatusExecucao $de,
        StatusExecucao $para,
        int $mecanicoId,
        ?int $duracaoSegundos,
        ?string $observacao,
        ?\DateTimeImmutable $ocorridoEm = null,
    ): EventoTimeline {
        $evento = new EventoTimeline(
            osId: $osId,
            tipo: $tipo,
            statusAnterior: $de,
            statusNovo: $para,
            mecanicoId: $mecanicoId,
            duracaoSegundos: $duracaoSegundos,
            observacao: $observacao,
            traceId: Tracing::traceIdAtual(),
            ocorridoEm: $ocorridoEm ?? CarbonImmutable::now('UTC')->toDateTimeImmutable(),
        );

        $this->timeline->registrar($evento);

        Log::info('os_transicao_status', [
            'os.id'               => $osId,
            'os.status_anterior'  => $de->value,
            'os.status_novo'      => $para->value,
            'os.duracao_segundos' => $duracaoSegundos,
        ]);

        return $evento;
    }
}
