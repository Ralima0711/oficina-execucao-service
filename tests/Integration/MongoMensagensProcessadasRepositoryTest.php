<?php

namespace Tests\Integration;

use App\Infrastructure\Persistence\Mongo\MongoMensagensProcessadasRepository;
use MongoDB\Database;
use Tests\TestCase;

/**
 * Contra um MongoDB de verdade (banco `execucao_test`). Sem Mongo acessível, os
 * testes são pulados.
 */
class MongoMensagensProcessadasRepositoryTest extends TestCase
{
    private MongoMensagensProcessadasRepository $repo;

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

        $this->database->selectCollection(MongoMensagensProcessadasRepository::COLECAO)->drop();
        $this->repo = new MongoMensagensProcessadasRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            $this->database->selectCollection(MongoMensagensProcessadasRepository::COLECAO)->drop();
        }
        parent::tearDown();
    }

    public function test_primeira_vez_marca_e_repeticao_e_descartada(): void
    {
        $id = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

        $this->assertTrue($this->repo->marcarSeNova($id));
        $this->assertFalse($this->repo->marcarSeNova($id));
        $this->assertFalse($this->repo->marcarSeNova($id));
    }

    public function test_mensagens_diferentes_nao_se_afetam(): void
    {
        $this->assertTrue($this->repo->marcarSeNova('mensagem-a'));
        $this->assertTrue($this->repo->marcarSeNova('mensagem-b'));
    }

    public function test_liberar_permite_reprocessar_a_mensagem_que_falhou(): void
    {
        $this->repo->marcarSeNova('mensagem-a');

        $this->repo->liberar('mensagem-a');

        $this->assertTrue($this->repo->marcarSeNova('mensagem-a'));
    }

    public function test_liberar_mensagem_desconhecida_nao_falha(): void
    {
        $this->repo->liberar('nunca-vista');

        $this->assertTrue($this->repo->marcarSeNova('nunca-vista'));
    }

    public function test_o_indice_ttl_apaga_os_registros_antigos(): void
    {
        $this->repo->criarIndices();
        $this->repo->criarIndices();

        $ttl = null;
        foreach ($this->database->selectCollection(MongoMensagensProcessadasRepository::COLECAO)->listIndexes() as $indice) {
            if ($indice->getName() === 'processada_ttl') {
                $ttl = $indice['expireAfterSeconds'];
            }
        }

        $this->assertSame(30 * 86400, $ttl);
    }
}
