<?php

namespace Tests\Unit;

use App\Application\Services\ExecucaoService;
use App\Application\Services\FilaExecucaoService;
use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\Exceptions\ConflitoException;
use App\Domain\Execucao\ValueObjects\TipoEvento;
use Tests\Support\FilaEmMemoria;
use Tests\Support\Ids;
use Tests\Support\TimelineEmMemoria;
use Tests\TestCase;

/**
 * Ler o status e depois gravar não é atômico: duas requisições simultâneas leem o
 * mesmo status e passam juntas pela checagem. A timeline barra a segunda (índice
 * único no Mongo). Aqui a leitura "velha" é simulada com uma timeline que devolve
 * sempre o status que tinha antes da primeira gravação.
 */
class ExecucaoServiceTest extends TestCase
{
    public function test_duas_finalizacoes_simultaneas_gravam_uma_so_e_a_segunda_conflita(): void
    {
        $fila = new FilaEmMemoria();
        $timeline = new TimelineComLeituraCongelavel();
        $fila_ = new FilaExecucaoService($fila, $timeline);
        $execucao = new ExecucaoService($timeline, $fila);

        $fila_->enfileirar(Ids::os(10));
        $fila_->proxima(Ids::mecanico(7));

        $timeline->congelarLeitura(Ids::os(10));

        $execucao->finalizar(Ids::os(10), Ids::mecanico(7));

        try {
            $execucao->finalizar(Ids::os(10), Ids::mecanico(7));
            $this->fail('A segunda finalização deveria conflitar.');
        } catch (ConflitoException $e) {
            $this->assertSame('A OS ' . Ids::os(10) . ' já tem o evento EXECUCAO_FINALIZADA.', $e->getMessage());
        }

        $this->assertSame(1, $this->contar($timeline, Ids::os(10), TipoEvento::EXECUCAO_FINALIZADA));
    }

    public function test_dois_inicios_de_diagnostico_simultaneos_gravam_um_so(): void
    {
        $timeline = new TimelineComLeituraCongelavel();
        $execucao = new ExecucaoService($timeline, new FilaEmMemoria());

        $timeline->congelarLeitura(Ids::os(10));

        $execucao->iniciarDiagnostico(Ids::os(10), Ids::mecanico(7));

        $this->expectException(ConflitoException::class);
        try {
            $execucao->iniciarDiagnostico(Ids::os(10), Ids::mecanico(8));
        } finally {
            $this->assertSame(1, $this->contar($timeline, Ids::os(10), TipoEvento::DIAGNOSTICO_INICIADO));
        }
    }

    public function test_a_segunda_finalizacao_nao_libera_o_mecanico_de_outra_os(): void
    {
        $fila = new FilaEmMemoria();
        $timeline = new TimelineComLeituraCongelavel();
        $filaService = new FilaExecucaoService($fila, $timeline);
        $execucao = new ExecucaoService($timeline, $fila);

        $filaService->enfileirar(Ids::os(10));
        $filaService->proxima(Ids::mecanico(7));
        $timeline->congelarLeitura(Ids::os(10));
        $execucao->finalizar(Ids::os(10), Ids::mecanico(7));

        // O mecânico já puxou outra OS quando a finalização duplicada chega.
        $filaService->enfileirar(Ids::os(11));
        $filaService->proxima(Ids::mecanico(7));

        try {
            $execucao->finalizar(Ids::os(10), Ids::mecanico(7));
        } catch (ConflitoException) {
        }

        $this->assertSame(Ids::os(11), $fila->osEmExecucao(Ids::mecanico(7)));
    }

    private function contar(TimelineEmMemoria $timeline, string $osId, TipoEvento $tipo): int
    {
        return count(array_filter($timeline->porOs($osId), fn (EventoTimeline $e) => $e->tipo === $tipo));
    }
}

/** Timeline que, depois de congelada para uma OS, continua devolvendo o status antigo. */
class TimelineComLeituraCongelavel extends TimelineEmMemoria
{
    /** @var array<string, EventoTimeline|null> */
    private array $congelados = [];

    public function congelarLeitura(string $osId): void
    {
        $this->congelados[$osId] = parent::ultimoEventoDeEstado($osId);
    }

    public function ultimoEventoDeEstado(string $osId): ?EventoTimeline
    {
        return array_key_exists($osId, $this->congelados) ? $this->congelados[$osId] : parent::ultimoEventoDeEstado($osId);
    }
}
