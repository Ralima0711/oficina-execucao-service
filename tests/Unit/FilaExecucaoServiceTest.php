<?php

namespace Tests\Unit;

use App\Application\Services\FilaExecucaoService;
use App\Domain\Execucao\Entities\EventoTimeline;
use Tests\Support\FilaEmMemoria;
use Tests\Support\TimelineEmMemoria;
use Tests\TestCase;

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
        $service = new FilaExecucaoService($fila, $timeline);

        $service->enfileirar(10);
        $service->enfileirar(11);

        try {
            $service->proxima(7);
            $this->fail('A falha da timeline deveria ser repropagada.');
        } catch (\RuntimeException $e) {
            $this->assertSame('mongo fora do ar', $e->getMessage());
        }

        // A OS 10 não sumiu: continua na frente da fila, e o mecânico 7 não ficou preso a ela.
        $this->assertSame(1, $fila->posicao(10));
        $this->assertSame(2, $fila->posicao(11));
        $this->assertNull($fila->osEmExecucao(7));
    }

    public function test_proxima_com_fila_vazia_devolve_null(): void
    {
        $service = new FilaExecucaoService(new FilaEmMemoria(), new TimelineEmMemoria());

        $this->assertNull($service->proxima(7));
    }
}
