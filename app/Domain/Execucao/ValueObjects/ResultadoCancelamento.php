<?php

namespace App\Domain\Execucao\ValueObjects;

/**
 * O que o comando CancelarExecucao fez (contrato v1.1, Parte 3b). Só RETIRADA_DA_FILA e
 * REPARO_ENCERRADO publicam execucao.falhou; nos demais casos a compensação é pulada,
 * e pular não é erro.
 */
enum ResultadoCancelamento: string
{
    /** Ainda na fila, mecânico não puxou: saiu da fila e o execucaoId foi liberado. */
    case RETIRADA_DA_FILA = 'retirada_da_fila';

    /** Mecânico já puxou: o reparo foi encerrado e o mecânico liberado. */
    case REPARO_ENCERRADO = 'reparo_encerrado';

    /** Já concluída: não desfaz. A tentativa fica registrada na timeline. */
    case JA_CONCLUIDA = 'ja_concluida';

    /** Já falhou ou já foi cancelada: o efeito é um só, repetir não faz nada. */
    case JA_ENCERRADA = 'ja_encerrada';

    /** A OS nunca chegou à fila (ou já saiu dela por outro caminho): nada a desfazer. */
    case NAO_SE_APLICA = 'nao_se_aplica';

    public function publicaFalha(): bool
    {
        return $this === self::RETIRADA_DA_FILA || $this === self::REPARO_ENCERRADO;
    }
}
