<?php

namespace Tests\Unit;

use App\Domain\Execucao\ValueObjects\ContextoSaga;
use App\Domain\Execucao\ValueObjects\ResultadoCancelamento;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regras pequenas dos value objects da saga que decidem o que vai para a timeline
 * e se a compensação publica execucao.falhou.
 */
class ValueObjectsDaSagaTest extends TestCase
{
    private const SAGA_ID = '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f';
    private const COMANDO_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    public function test_trace_id_sai_do_traceparent_w3c_valido(): void
    {
        $saga = new ContextoSaga(self::SAGA_ID, self::COMANDO_ID, '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $saga->traceId());
    }

    /** @return array<string, array{?string}> */
    public static function traceparentsSemTraceId(): array
    {
        return [
            'ausente'               => [null],
            'lixo'                  => ['lixo'],
            'trace-id curto'        => ['00-0af7651916cd43dd-b7ad6b7169203331-01'],
            'hex maiúsculo'         => ['00-0AF7651916CD43DD8448EB211C80319C-B7AD6B7169203331-01'],
            'sem o campo de flags'  => ['00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331'],
        ];
    }

    #[DataProvider('traceparentsSemTraceId')]
    public function test_traceparent_ausente_ou_fora_do_formato_nao_vira_trace_id(?string $traceparent): void
    {
        $this->assertNull((new ContextoSaga(self::SAGA_ID, self::COMANDO_ID, $traceparent))->traceId());
    }

    /** @return array<string, array{ResultadoCancelamento, bool}> */
    public static function resultados(): array
    {
        return [
            'retirada da fila' => [ResultadoCancelamento::RETIRADA_DA_FILA, true],
            'reparo encerrado' => [ResultadoCancelamento::REPARO_ENCERRADO, true],
            'já concluída'     => [ResultadoCancelamento::JA_CONCLUIDA, false],
            'já encerrada'     => [ResultadoCancelamento::JA_ENCERRADA, false],
            'não se aplica'    => [ResultadoCancelamento::NAO_SE_APLICA, false],
        ];
    }

    #[DataProvider('resultados')]
    public function test_so_retirar_da_fila_ou_encerrar_o_reparo_publica_execucao_falhou(ResultadoCancelamento $resultado, bool $publica): void
    {
        $this->assertSame($publica, $resultado->publicaFalha());
    }
}
