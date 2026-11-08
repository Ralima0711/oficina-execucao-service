<?php

namespace App\Application\Services;

use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Exceptions\NaoEncontradoException;
use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;
use App\Domain\Execucao\Repositories\TimelineRepositoryInterface;
use App\Domain\Execucao\ValueObjects\ContextoSaga;
use App\Domain\Execucao\ValueObjects\ResultadoCancelamento;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use App\Infrastructure\Observability\Tracing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExecucaoService
{
    public function __construct(
        private TimelineRepositoryInterface $timeline,
        private FilaExecucaoRepositoryInterface $fila,
        private EventosDeExecucao $eventos,
    ) {}

    public function iniciarDiagnostico(string $osId, string $mecanicoId, ?string $observacao = null): EventoTimeline
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

    public function registrarNota(string $osId, string $mecanicoId, string $texto): EventoTimeline
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

    public function finalizar(string $osId, string $mecanicoId, ?string $observacao = null): EventoTimeline
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
                $ultimo->execucaoId,
                $ultimo->saga,
            );

            // Libera o mecânico para puxar a próxima OS. Idempotente.
            $this->fila->concluir($mecanicoId, $osId);

            $this->eventos->concluida($evento, $ultimo->ocorridoEm);

            return $evento;
        });
    }

    /**
     * O mecânico informa que o reparo não pode ser concluído: EM_EXECUCAO → FALHOU, o mecânico
     * é liberado e a Execução publica execucao.falhou para o orquestrador compensar.
     */
    public function falhar(string $osId, string $mecanicoId, string $motivo): EventoTimeline
    {
        return Tracing::executar('ExecucaoService::falhar', ['os.id' => $osId, 'os.mecanico_id' => $mecanicoId], function () use ($osId, $mecanicoId, $motivo) {
            $ultimo = $this->timeline->ultimoEventoDeEstado($osId);
            $atual = $ultimo?->statusNovo ?? StatusExecucao::RECEBIDA;

            if ($atual !== StatusExecucao::EM_EXECUCAO) {
                throw new \DomainException("Somente ordens EM_EXECUCAO podem falhar (status atual: {$atual->value}).");
            }

            $this->exigirMecanicoResponsavel($osId, $ultimo, $mecanicoId);

            return $this->encerrarReparoComFalha($osId, $ultimo, $motivo, $motivo, $ultimo->saga);
        });
    }

    /**
     * CancelarExecucao (compensação da saga, contrato v1.1 Parte 3b). O que faz depende de onde a
     * OS está; compensação que não se aplica é pulada, não é erro, e repetida tem um efeito só.
     *
     *   na fila, ninguém puxou → sai da fila, libera o execucaoId, publica execucao.falhou ("cancelada")
     *   mecânico já puxou      → encerra o reparo, libera o mecânico, registra na timeline, publica execucao.falhou
     *   já concluída           → não desfaz; registra a tentativa na timeline (o que se desfaz é o dinheiro, pelo estorno)
     *   já falhou / nunca veio → nada a fazer
     *
     * @param ContextoSaga $saga a saga e a mensagem (o CancelarExecucao) a que o execucao.falhou responde
     */
    public function cancelar(string $osId, string $motivo, ContextoSaga $saga): ResultadoCancelamento
    {
        return Tracing::executar('ExecucaoService::cancelar', ['os.id' => $osId, 'saga.id' => $saga->sagaId], function () use ($osId, $motivo, $saga) {
            $ultimo = $this->timeline->ultimoEventoDeEstado($osId);

            $resultado = match ($ultimo?->statusNovo) {
                StatusExecucao::FINALIZADA   => $this->registrarCancelamentoDeExecucaoConcluida($osId, $ultimo, $motivo, $saga),
                StatusExecucao::FALHOU       => ResultadoCancelamento::JA_ENCERRADA,
                StatusExecucao::EM_EXECUCAO  => $this->encerrarReparoCancelado($osId, $ultimo, $motivo, $saga),
                default                      => $this->retirarDaFilaPorCancelamento($osId, $motivo, $saga),
            };

            Log::info('execucao_cancelamento_tratado', [
                'os.id'      => $osId,
                'saga.id'    => $saga->sagaId,
                'resultado'  => $resultado->value,
                'cancelamento.motivo' => $motivo,
            ]);

            return $resultado;
        });
    }

    /** @return EventoTimeline[] */
    public function timeline(string $osId): array
    {
        $eventos = $this->timeline->porOs($osId);

        if ($eventos === []) {
            throw new NaoEncontradoException("A OS {$osId} não tem eventos na timeline.");
        }

        return $eventos;
    }

    /** @return array<int, array{status: string, tempoMedioSegundos: float, amostras: int}> */
    public function tempoMedioPorStatus(): array
    {
        return $this->timeline->tempoMedioPorStatus();
    }

    private function registrarCancelamentoDeExecucaoConcluida(string $osId, EventoTimeline $ultimo, string $motivo, ContextoSaga $saga): ResultadoCancelamento
    {
        // NOTA não muda o estado: a OS continua FINALIZADA, e cada tentativa fica registrada.
        $this->timeline->registrar(new EventoTimeline(
            osId: $osId,
            tipo: TipoEvento::NOTA,
            statusAnterior: null,
            statusNovo: null,
            mecanicoId: $ultimo->mecanicoId,
            duracaoSegundos: null,
            observacao: "CancelarExecucao ignorado: a execução já foi concluída e não se desfaz (motivo: {$motivo}).",
            traceId: $saga->traceId() ?? Tracing::traceIdAtual(),
            ocorridoEm: CarbonImmutable::now('UTC')->toDateTimeImmutable(),
            execucaoId: $ultimo->execucaoId,
            saga: $saga,
        ));

        return ResultadoCancelamento::JA_CONCLUIDA;
    }

    private function encerrarReparoCancelado(string $osId, EventoTimeline $ultimo, string $motivo, ContextoSaga $saga): ResultadoCancelamento
    {
        $this->encerrarReparoComFalha($osId, $ultimo, 'cancelada', "cancelada ({$motivo})", $saga);

        return ResultadoCancelamento::REPARO_ENCERRADO;
    }

    /**
     * EM_EXECUCAO → FALHOU: grava a transição, libera o mecânico (idempotente) e publica
     * execucao.falhou. Quem chama já conferiu o status e o mecânico responsável.
     *
     * @param string $motivo      vai no evento
     * @param string $observacao  fica na timeline
     */
    private function encerrarReparoComFalha(string $osId, EventoTimeline $ultimo, string $motivo, string $observacao, ?ContextoSaga $saga): EventoTimeline
    {
        $agora = CarbonImmutable::now('UTC')->toDateTimeImmutable();

        $evento = $this->registrarTransicao(
            $osId,
            TipoEvento::EXECUCAO_FALHOU,
            StatusExecucao::EM_EXECUCAO,
            StatusExecucao::FALHOU,
            $ultimo->mecanicoId,
            // Tempo do reparo até a falha: desde EXECUCAO_INICIADA.
            max(0, $agora->getTimestamp() - $ultimo->ocorridoEm->getTimestamp()),
            $observacao,
            $agora,
            $ultimo->execucaoId,
            $saga,
        );

        if ($ultimo->mecanicoId !== null) {
            $this->fila->concluir($ultimo->mecanicoId, $osId);
        }

        $this->eventos->falhou($evento, $motivo, $ultimo->ocorridoEm);

        return $evento;
    }

    private function retirarDaFilaPorCancelamento(string $osId, string $motivo, ContextoSaga $saga): ResultadoCancelamento
    {
        $item = $this->fila->retirar($osId);

        if ($item === null) {
            // Nunca chegou à fila (ou já saiu dela): nada a desfazer. Responder sucesso evita
            // travar a compensação, e o comando fica no log.
            return ResultadoCancelamento::NAO_SE_APLICA;
        }

        $execucaoId = $item->execucaoId ?? (string) Str::uuid();
        $agora = CarbonImmutable::now('UTC')->toDateTimeImmutable();

        try {
            $evento = $this->registrarTransicao(
                $osId,
                TipoEvento::EXECUCAO_FALHOU,
                StatusExecucao::APROVADA,
                StatusExecucao::FALHOU,
                null,
                // Tempo que a OS esperou na fila (permaneceu em APROVADA).
                max(0, $agora->getTimestamp() - $item->enfileiradaEm->getTimestamp()),
                "cancelada ({$motivo})",
                $agora,
                $execucaoId,
                $saga,
            );
        } catch (ConflitoException $e) {
            // Alguém já gravou o fim desta execução: o que importava aconteceu, a OS não volta à fila.
            throw $e;
        } catch (\Throwable $e) {
            // Sem a transição na timeline a OS não pode sumir da fila: ela volta, com a mesma posição.
            $this->fila->enfileirar($osId, $item->prioridade, $execucaoId, $item->enfileiradaEm, $item->saga);

            throw $e;
        }

        $this->eventos->falhou($evento, 'cancelada');

        return ResultadoCancelamento::RETIRADA_DA_FILA;
    }

    private function statusAtual(string $osId): StatusExecucao
    {
        return $this->timeline->ultimoEventoDeEstado($osId)?->statusNovo ?? StatusExecucao::RECEBIDA;
    }

    private function exigirMecanicoResponsavel(string $osId, ?EventoTimeline $ultimo, string $mecanicoId): void
    {
        if ($ultimo?->mecanicoId !== null && $ultimo->mecanicoId !== $mecanicoId) {
            throw new ConflitoException("A OS {$osId} está com o mecânico {$ultimo->mecanicoId}.");
        }
    }

    private function registrarTransicao(
        string $osId,
        TipoEvento $tipo,
        StatusExecucao $de,
        StatusExecucao $para,
        ?string $mecanicoId,
        ?int $duracaoSegundos,
        ?string $observacao,
        ?\DateTimeImmutable $ocorridoEm = null,
        ?string $execucaoId = null,
        ?ContextoSaga $saga = null,
    ): EventoTimeline {
        $evento = new EventoTimeline(
            osId: $osId,
            tipo: $tipo,
            statusAnterior: $de,
            statusNovo: $para,
            mecanicoId: $mecanicoId,
            duracaoSegundos: $duracaoSegundos,
            observacao: $observacao,
            traceId: $saga?->traceId() ?? Tracing::traceIdAtual(),
            ocorridoEm: $ocorridoEm ?? CarbonImmutable::now('UTC')->toDateTimeImmutable(),
            execucaoId: $execucaoId,
            saga: $saga,
        );

        $this->timeline->registrar($evento);

        Log::info('os_transicao_status', [
            'os.id'               => $osId,
            'execucao.id'         => $execucaoId,
            'os.status_anterior'  => $de->value,
            'os.status_novo'      => $para->value,
            'os.duracao_segundos' => $duracaoSegundos,
        ]);

        return $evento;
    }
}
