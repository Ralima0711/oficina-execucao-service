<?php

namespace Tests\Integration;

use App\Infrastructure\Persistence\Mongo\MongoMensagensProcessadasRepository;
use App\Infrastructure\Persistence\Mongo\MongoTimelineRepository;
use MongoDB\Database;
use Tests\TestCase;

/**
 * `php artisan execucao:criar-indices` contra um MongoDB de verdade (banco `execucao_test`).
 * É o comando que a API e o worker rodam antes de subir: se ele falhar, o serviço não sobe.
 * Sem Mongo acessível, os testes são pulados.
 */
class CriarIndicesCommandTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mongodb.database' => 'execucao_test']);

        $this->database = $this->app->make(Database::class);

        try {
            $this->database->command(['ping' => 1]);
        } catch (\Throwable) {
            $this->markTestSkipped('MongoDB indisponível.');
        }

        $this->droparColecoes();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->droparColecoes();
        }
        parent::tearDown();
    }

    public function test_cria_os_indices_da_timeline_e_das_mensagens_processadas(): void
    {
        $this->artisan('execucao:criar-indices')
            ->expectsOutput('Índices de timeline_os criados.')
            ->expectsOutput('Índice de mensagens_processadas criado.')
            ->assertSuccessful();

        $timeline = $this->indices(MongoTimelineRepository::COLECAO);
        $this->assertArrayHasKey('os_ocorrido', $timeline);
        $this->assertArrayHasKey('tipo_ocorrido', $timeline);
        $this->assertArrayHasKey('os_tipo_estado_unico', $timeline);
        $this->assertTrue($timeline['os_tipo_estado_unico']->isUnique());

        $mensagens = $this->indices(MongoMensagensProcessadasRepository::COLECAO);
        $this->assertArrayHasKey('processada_ttl', $mensagens);
        $this->assertSame(
            (int) config('mongodb.mensagens_processadas_ttl_dias') * 86400,
            $mensagens['processada_ttl']['expireAfterSeconds']
        );
    }

    public function test_rodar_duas_vezes_nao_falha_nem_duplica_indices(): void
    {
        $this->artisan('execucao:criar-indices')->assertSuccessful();
        $antes = array_keys($this->indices(MongoTimelineRepository::COLECAO));

        $this->artisan('execucao:criar-indices')->assertSuccessful();

        $this->assertSame($antes, array_keys($this->indices(MongoTimelineRepository::COLECAO)));
    }

    /** @return array<string, \MongoDB\Model\IndexInfo> nome => índice */
    private function indices(string $colecao): array
    {
        $indices = [];
        foreach ($this->database->selectCollection($colecao)->listIndexes() as $indice) {
            $indices[$indice->getName()] = $indice;
        }

        return $indices;
    }

    private function droparColecoes(): void
    {
        $this->database->selectCollection(MongoTimelineRepository::COLECAO)->drop();
        $this->database->selectCollection(MongoMensagensProcessadasRepository::COLECAO)->drop();
    }
}
