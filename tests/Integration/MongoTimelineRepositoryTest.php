<?php

namespace Tests\Integration;

use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\ValueObjects\StatusExecucao;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use App\Infrastructure\Persistence\Mongo\MongoTimelineRepository;
use MongoDB\Database;
use Tests\TestCase;

/**
 * Contra um MongoDB de verdade (banco `execucao_test`). Roda dentro do compose:
 * `docker compose exec app vendor/bin/phpunit --testsuite Integration`.
 * Sem Mongo acessível, os testes são pulados.
 */
class MongoTimelineRepositoryTest extends TestCase
{
    private MongoTimelineRepository $repo;

    private Database $database;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca o banco de desenvolvimento: o env do compose (MONGO_DATABASE) vence o
        // phpunit.xml, então o banco de testes é imposto na config antes de resolver o Database.
        config(['mongodb.database' => 'execucao_test']);

        $this->database = $this->app->make(Database::class);

        try {
            $this->database->command(['ping' => 1]);
        } catch (\Throwable) {
            $this->markTestSkipped('MongoDB indisponível.');
        }

        $this->database->selectCollection(MongoTimelineRepository::COLECAO)->drop();
        $this->repo = new MongoTimelineRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            $this->database->selectCollection(MongoTimelineRepository::COLECAO)->drop();
        }
        parent::tearDown();
    }

    public function test_usa_o_banco_de_testes(): void
    {
        $this->assertSame('execucao_test', $this->database->getDatabaseName());
    }

    public function test_registra_e_le_a_timeline_em_ordem_cronologica(): void
    {
        // Inseridos fora de ordem: a leitura ordena por ocorridoEm.
        $this->repo->registrar($this->evento(1, TipoEvento::EXECUCAO_INICIADA, '14:10:00'));
        $this->repo->registrar($this->evento(1, TipoEvento::DIAGNOSTICO_INICIADO, '14:00:00'));
        $this->repo->registrar($this->evento(2, TipoEvento::DIAGNOSTICO_INICIADO, '14:05:00'));

        $eventos = $this->repo->porOs(1);

        $this->assertSame(
            [TipoEvento::DIAGNOSTICO_INICIADO, TipoEvento::EXECUCAO_INICIADA],
            array_map(fn (EventoTimeline $e) => $e->tipo, $eventos)
        );
        $this->assertSame([], $this->repo->porOs(404));
    }

    public function test_preserva_todos_os_campos_do_evento(): void
    {
        $original = new EventoTimeline(
            osId: 5,
            tipo: TipoEvento::EXECUCAO_FINALIZADA,
            statusAnterior: StatusExecucao::EM_EXECUCAO,
            statusNovo: StatusExecucao::FINALIZADA,
            mecanicoId: 7,
            duracaoSegundos: 5400,
            observacao: 'pastilhas trocadas',
            traceId: '0af7651916cd43dd8448eb211c80319c',
            ocorridoEm: new \DateTimeImmutable('2026-10-27 15:35:00.456', new \DateTimeZone('UTC')),
        );

        $this->repo->registrar($original);

        $this->assertSame($original->toArray(), $this->repo->porOs(5)[0]->toArray());
    }

    public function test_nota_volta_sem_status_nem_duracao(): void
    {
        $this->repo->registrar(new EventoTimeline(5, TipoEvento::NOTA, null, null, 7, null, 'anotação', null, new \DateTimeImmutable()));

        $nota = $this->repo->porOs(5)[0];

        $this->assertNull($nota->statusNovo);
        $this->assertNull($nota->duracaoSegundos);
        $this->assertSame('anotação', $nota->observacao);
    }

    public function test_ultimo_evento_de_estado_ignora_notas(): void
    {
        $this->repo->registrar($this->evento(1, TipoEvento::DIAGNOSTICO_INICIADO, '14:00:00'));
        $this->repo->registrar($this->evento(1, TipoEvento::NOTA, '14:30:00'));

        $ultimo = $this->repo->ultimoEventoDeEstado(1);

        $this->assertSame(TipoEvento::DIAGNOSTICO_INICIADO, $ultimo->tipo);
        $this->assertNull($this->repo->ultimoEventoDeEstado(404));
    }

    public function test_tempo_medio_por_status(): void
    {
        $this->repo->registrar($this->evento(1, TipoEvento::EXECUCAO_FINALIZADA, '15:00:00', StatusExecucao::EM_EXECUCAO, 3000));
        $this->repo->registrar($this->evento(2, TipoEvento::EXECUCAO_FINALIZADA, '15:00:00', StatusExecucao::EM_EXECUCAO, 5000));
        $this->repo->registrar($this->evento(3, TipoEvento::EXECUCAO_INICIADA, '14:00:00', StatusExecucao::APROVADA, 600));
        // Sem duração (diagnóstico) não entra na média.
        $this->repo->registrar($this->evento(4, TipoEvento::DIAGNOSTICO_INICIADO, '14:00:00', StatusExecucao::RECEBIDA, null));

        $this->assertSame([
            ['status' => 'APROVADA', 'tempoMedioSegundos' => 600.0, 'amostras' => 1],
            ['status' => 'EM_EXECUCAO', 'tempoMedioSegundos' => 4000.0, 'amostras' => 2],
        ], $this->repo->tempoMedioPorStatus());
    }

    public function test_cria_os_indices_de_forma_idempotente(): void
    {
        $this->repo->criarIndices();
        $this->repo->criarIndices();

        $nomes = [];
        foreach ($this->database->selectCollection(MongoTimelineRepository::COLECAO)->listIndexes() as $indice) {
            $nomes[] = $indice->getName();
        }

        $this->assertContains('os_ocorrido', $nomes);
        $this->assertContains('tipo_ocorrido', $nomes);
    }

    private function evento(
        int $osId,
        TipoEvento $tipo,
        string $hora,
        ?StatusExecucao $anterior = null,
        ?int $duracao = null,
    ): EventoTimeline {
        return new EventoTimeline(
            $osId,
            $tipo,
            $anterior,
            null,
            7,
            $duracao,
            null,
            null,
            new \DateTimeImmutable("2026-10-27 {$hora}", new \DateTimeZone('UTC'))
        );
    }
}
