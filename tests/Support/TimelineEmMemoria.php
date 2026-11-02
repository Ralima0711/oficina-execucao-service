<?php

namespace Tests\Support;

use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\Repositories\TimelineRepositoryInterface;
use App\Domain\Execucao\ValueObjects\TipoEvento;

/** Fake em memória da timeline (append-only, como o Mongo). */
class TimelineEmMemoria implements TimelineRepositoryInterface
{
    /** @var EventoTimeline[] */
    public array $eventos = [];

    public function registrar(EventoTimeline $evento): void
    {
        $this->eventos[] = $evento;
    }

    public function porOs(int $osId): array
    {
        return array_values(array_filter($this->eventos, fn (EventoTimeline $e) => $e->osId === $osId));
    }

    public function ultimoEventoDeEstado(int $osId): ?EventoTimeline
    {
        $estado = array_filter($this->porOs($osId), fn (EventoTimeline $e) => $e->tipo !== TipoEvento::NOTA);

        return $estado === [] ? null : end($estado);
    }

    public function tempoMedioPorStatus(): array
    {
        $grupos = [];
        foreach ($this->eventos as $e) {
            if ($e->duracaoSegundos === null || $e->statusAnterior === null) {
                continue;
            }
            $grupos[$e->statusAnterior->value][] = $e->duracaoSegundos;
        }
        ksort($grupos);

        $resultado = [];
        foreach ($grupos as $status => $duracoes) {
            $resultado[] = [
                'status'             => $status,
                'tempoMedioSegundos' => round(array_sum($duracoes) / count($duracoes), 2),
                'amostras'           => count($duracoes),
            ];
        }

        return $resultado;
    }
}
