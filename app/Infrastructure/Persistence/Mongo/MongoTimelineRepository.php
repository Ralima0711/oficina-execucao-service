<?php

namespace App\Infrastructure\Persistence\Mongo;

use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\Repositories\TimelineRepositoryInterface;
use App\Domain\Execucao\ValueObjects\ContextoSaga;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;

class MongoTimelineRepository implements TimelineRepositoryInterface
{
    public const COLECAO = 'timeline_os';

    private const INDICE_TRANSICAO_UNICA = 'os_tipo_estado_unico';

    public function __construct(private Database $database) {}

    /**
     * @throws ConflitoException se a OS já tem esse evento de transição (cada transição
     *                           acontece uma vez por OS; o índice único barra a corrida)
     */
    public function registrar(EventoTimeline $evento): void
    {
        try {
            $this->colecao()->insertOne([
                'osId'            => $evento->osId,
                'execucaoId'      => $evento->execucaoId,
                'saga'            => $evento->saga?->toArray(),
                'tipo'            => $evento->tipo->value,
                'statusAnterior'  => $evento->statusAnterior?->value,
                'statusNovo'      => $evento->statusNovo?->value,
                'mecanicoId'      => $evento->mecanicoId,
                'duracaoSegundos' => $evento->duracaoSegundos,
                'observacao'      => $evento->observacao,
                'traceId'         => $evento->traceId,
                'ocorridoEm'      => new UTCDateTime((int) $evento->ocorridoEm->format('Uv')),
            ]);
        } catch (\Throwable $e) {
            if ($evento->tipo->mudaEstado() && ChaveDuplicada::e($e)) {
                throw new ConflitoException("A OS {$evento->osId} já tem o evento {$evento->tipo->value}.");
            }

            throw $e;
        }
    }

    public function porOs(string $osId): array
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

    public function ultimoEventoDeEstado(string $osId): ?EventoTimeline
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
        $this->descartarIndiceDeTransicaoDesatualizado();

        $this->colecao()->createIndexes([
            // Consulta da timeline de uma OS, em ordem.
            ['key' => ['osId' => 1, 'ocorridoEm' => 1], 'name' => 'os_ocorrido'],
            // Métricas por tipo de evento ao longo do tempo.
            ['key' => ['tipo' => 1, 'ocorridoEm' => 1], 'name' => 'tipo_ocorrido'],
            // Cada transição acontece uma vez por OS. Duas requisições simultâneas passam juntas
            // pela checagem de status (ler e depois gravar não é atômico); este índice barra a
            // segunda. Notas ficam de fora: podem se repetir.
            [
                'key'                     => ['osId' => 1, 'tipo' => 1],
                'name'                    => self::INDICE_TRANSICAO_UNICA,
                'unique'                  => true,
                'partialFilterExpression' => ['tipo' => ['$in' => $this->tiposDeTransicao()]],
            ],
        ]);
    }

    /**
     * O índice único cobre a lista de tipos de transição, e a lista cresce (EXECUCAO_FALHOU). O Mongo
     * recusa recriar um índice de mesmo nome com outro filtro, então o que foi criado com a lista
     * antiga é descartado e recriado em seguida: sem isso, o `execucao:criar-indices` de quem já
     * tinha o banco quebraria a subida do serviço.
     */
    private function descartarIndiceDeTransicaoDesatualizado(): void
    {
        foreach ($this->colecao()->listIndexes() as $indice) {
            if ($indice->getName() !== self::INDICE_TRANSICAO_UNICA) {
                continue;
            }

            $filtro = $indice['partialFilterExpression'] ?? null;
            $tipos = $filtro === null ? [] : ((array) ($filtro['tipo'] ?? []))['$in'] ?? [];
            $tipos = $tipos instanceof \ArrayObject ? $tipos->getArrayCopy() : (array) $tipos;

            $esperados = $this->tiposDeTransicao();
            sort($tipos);
            sort($esperados);

            if ($tipos !== $esperados) {
                $this->colecao()->dropIndex(self::INDICE_TRANSICAO_UNICA);
            }
        }
    }

    /** @return string[] */
    private function tiposDeTransicao(): array
    {
        return array_values(array_map(
            fn (TipoEvento $tipo) => $tipo->value,
            array_filter(TipoEvento::cases(), fn (TipoEvento $tipo) => $tipo->mudaEstado())
        ));
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
            osId: (string) $d['osId'],
            tipo: TipoEvento::from($d['tipo']),
            statusAnterior: isset($d['statusAnterior']) ? StatusExecucao::from($d['statusAnterior']) : null,
            statusNovo: isset($d['statusNovo']) ? StatusExecucao::from($d['statusNovo']) : null,
            mecanicoId: isset($d['mecanicoId']) ? (string) $d['mecanicoId'] : null,
            duracaoSegundos: isset($d['duracaoSegundos']) ? (int) $d['duracaoSegundos'] : null,
            observacao: $d['observacao'] ?? null,
            traceId: $d['traceId'] ?? null,
            ocorridoEm: $ocorridoEm,
            execucaoId: $d['execucaoId'] ?? null,
            saga: ContextoSaga::fromArray(isset($d['saga']) ? (array) $d['saga'] : null),
        );
    }
}
