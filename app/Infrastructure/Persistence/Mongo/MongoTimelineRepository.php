<?php

namespace App\Infrastructure\Persistence\Mongo;

use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\Repositories\TimelineRepositoryInterface;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;

class MongoTimelineRepository implements TimelineRepositoryInterface
{
    public const COLECAO = 'timeline_os';

    public function __construct(private Database $database) {}

    public function registrar(EventoTimeline $evento): void
    {
        $this->colecao()->insertOne([
            'osId'            => $evento->osId,
            'tipo'            => $evento->tipo->value,
            'statusAnterior'  => $evento->statusAnterior?->value,
            'statusNovo'      => $evento->statusNovo?->value,
            'mecanicoId'      => $evento->mecanicoId,
            'duracaoSegundos' => $evento->duracaoSegundos,
            'observacao'      => $evento->observacao,
            'traceId'         => $evento->traceId,
            'ocorridoEm'      => new UTCDateTime((int) $evento->ocorridoEm->format('Uv')),
        ]);
    }

    public function porOs(int $osId): array
    {
        $cursor = $this->colecao()->find(
            ['osId' => $osId],
            ['sort' => ['ocorridoEm' => 1, '_id' => 1]]
        );

        $eventos = [];
        foreach ($cursor as $documento) {
            $eventos[] = $this->hidratar($documento);
        }

        return $eventos;
    }

    public function ultimoEventoDeEstado(int $osId): ?EventoTimeline
    {
        $documento = $this->colecao()->findOne(
            ['osId' => $osId, 'tipo' => ['$ne' => TipoEvento::NOTA->value]],
            ['sort' => ['ocorridoEm' => -1, '_id' => -1]]
        );

        return $documento === null ? null : $this->hidratar($documento);
    }

    public function tempoMedioPorStatus(): array
    {
        $cursor = $this->colecao()->aggregate([
            ['$match' => ['duracaoSegundos' => ['$ne' => null], 'statusAnterior' => ['$ne' => null]]],
            ['$group' => [
                '_id'      => '$statusAnterior',
                'media'    => ['$avg' => '$duracaoSegundos'],
                'amostras' => ['$sum' => 1],
            ]],
            ['$sort' => ['_id' => 1]],
        ]);

        $resultado = [];
        foreach ($cursor as $linha) {
            $resultado[] = [
                'status'             => (string) $linha['_id'],
                'tempoMedioSegundos' => round((float) $linha['media'], 2),
                'amostras'           => (int) $linha['amostras'],
            ];
        }

        return $resultado;
    }

    /** Cria os índices da coleção (idempotente). */
    public function criarIndices(): void
    {
        $this->colecao()->createIndexes([
            // Consulta da timeline de uma OS, em ordem.
            ['key' => ['osId' => 1, 'ocorridoEm' => 1], 'name' => 'os_ocorrido'],
            // Métricas por tipo de evento ao longo do tempo.
            ['key' => ['tipo' => 1, 'ocorridoEm' => 1], 'name' => 'tipo_ocorrido'],
        ]);
    }

    private function colecao(): Collection
    {
        return $this->database->selectCollection(self::COLECAO);
    }

    private function hidratar(object|array $documento): EventoTimeline
    {
        $d = $documento instanceof \ArrayObject ? $documento->getArrayCopy() : (array) $documento;

        $ocorridoEm = \DateTimeImmutable::createFromMutable($d['ocorridoEm']->toDateTime())
            ->setTimezone(new \DateTimeZone('UTC'));

        return new EventoTimeline(
            osId: (int) $d['osId'],
            tipo: TipoEvento::from($d['tipo']),
            statusAnterior: isset($d['statusAnterior']) ? StatusExecucao::from($d['statusAnterior']) : null,
            statusNovo: isset($d['statusNovo']) ? StatusExecucao::from($d['statusNovo']) : null,
            mecanicoId: isset($d['mecanicoId']) ? (int) $d['mecanicoId'] : null,
            duracaoSegundos: isset($d['duracaoSegundos']) ? (int) $d['duracaoSegundos'] : null,
            observacao: $d['observacao'] ?? null,
            traceId: $d['traceId'] ?? null,
            ocorridoEm: $ocorridoEm,
        );
    }
}
