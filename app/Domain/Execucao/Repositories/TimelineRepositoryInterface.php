<?php

namespace App\Domain\Execucao\Repositories;

use App\Domain\Execucao\Entities\EventoTimeline;

interface TimelineRepositoryInterface
{
    public function registrar(EventoTimeline $evento): void;

    /**
     * @return EventoTimeline[] do mais antigo para o mais recente
     */
    public function porOs(int $osId): array;

    /** Último evento que mudou o estado da OS (ignora notas). */
    public function ultimoEventoDeEstado(int $osId): ?EventoTimeline;

    /**
     * Tempo médio que as OS ficaram em cada status, a partir do status que
     * terminou (statusAnterior) e da duração da transição.
     *
     * @return array<int, array{status: string, tempoMedioSegundos: float, amostras: int}>
     */
    public function tempoMedioPorStatus(): array;
}
