<?php

namespace Tests\Unit;

use App\Application\Services\EventosDeExecucao;
use App\Application\Services\FilaExecucaoService;
use App\Domain\Execucao\Entities\EventoTimeline;
use App\Domain\Execucao\Exceptions\ConflitoException;
use Tests\Support\FilaEmMemoria;
use Tests\Support\TimelineEmMemoria;
use Tests\TestCase;
use Tests\Support\Ids;
use Tests\Support\PublicadorEmMemoria;

class FilaExecucaoServiceTest extends TestCase
{
    public function test_se_a_timeline_falha_a_os_volta_para_a_fila_e_o_mecanico_e_liberado(): void
    {
        $fila = new FilaEmMemoria();
        $timeline = new class extends TimelineEmMemoria {
            public function registrar(EventoTimeline $evento): void
            {
                throw new \RuntimeException('mongo fora do ar');
            }
        };
        $service = new FilaExecucaoService($fila, $timeline, new EventosDeExecucao(new PublicadorEmMemoria()));

        $service->enfileirar(Ids::os(10));
        $service->enfileirar(Ids::os(11));

        try {
            $service->proxima(Ids::mecanico(7));
            $this->fail('A falha da timeline deveria ser repropagada.');
        } catch (\RuntimeException $e) {
            $this->assertSame('mongo fora do ar', $e->getMessage());
        }

        // A OS 10 não sumiu: continua na frente da fila, e o mecânico 7 não ficou preso a ela.
        $this->assertSame(1, $fila->posicao(Ids::os(10)));
        $this->assertSame(2, $fila->posicao(Ids::os(11)));
        $this->assertNull($fila->osEmExecucao(Ids::mecanico(7)));
    }

    public function test_se_a_os_ja_tinha_o_evento_ela_nao_volta_para_a_fila_e_o_mecanico_e_liberado(): void
    {
        $fila = new FilaEmMemoria();
        $timeline = new class extends TimelineEmMemoria {
            public function registrar(EventoTimeline $evento): void
            {
                throw new ConflitoException('A OS já tem o evento EXECUCAO_INICIADA.');
            }
        };
        $service = new FilaExecucaoService($fila, $timeline, new EventosDeExecucao(new PublicadorEmMemoria()));

        $service->enfileirar(Ids::os(10));
        $service->enfileirar(Ids::os(11));

        $this->expectException(ConflitoException::class);
        try {
            $service->proxima(Ids::mecanico(7));
        } finally {
            // A OS 10 já tinha começado: devolvê-la travaria a fila nela. A 11 segue na vez.
            $this->assertNull($fila->posicao(Ids::os(10)));
            $this->assertSame(1, $fila->posicao(Ids::os(11)));
            $this->assertNull($fila->osEmExecucao(Ids::mecanico(7)));
        }
    }

    public function test_enfileirar_recusa_prioridade_fora_da_faixa(): void
    {
        $service = new FilaExecucaoService(new FilaEmMemoria(), new TimelineEmMemoria(), new EventosDeExecucao(new PublicadorEmMemoria()));

        foreach ([0, 6] as $invalida) {
            try {
                $service->enfileirar(Ids::os(10), $invalida);
                $this->fail("Prioridade {$invalida} deveria ser recusada.");
            } catch (\DomainException $e) {
                $this->assertSame('A prioridade deve estar entre 1 e 5.', $e->getMessage());
            }
        }
    }

    public function test_proxima_com_fila_vazia_devolve_null(): void
    {
        $service = new FilaExecucaoService(new FilaEmMemoria(), new TimelineEmMemoria(), new EventosDeExecucao(new PublicadorEmMemoria()));

        $this->assertNull($service->proxima(Ids::mecanico(7)));
    }
}
