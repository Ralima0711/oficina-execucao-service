<?php

namespace App\Domain\Execucao\ValueObjects;

/**
 * CAMADA DE DOMÍNIO — Value Object
 *
 * Tipo de um evento da timeline. NOTA é anotação livre do mecânico e não muda o
 * estado da OS; os demais são transições.
 */
enum TipoEvento: string
{
    case DIAGNOSTICO_INICIADO = 'DIAGNOSTICO_INICIADO';
    case EXECUCAO_INICIADA = 'EXECUCAO_INICIADA';
    case EXECUCAO_FINALIZADA = 'EXECUCAO_FINALIZADA';
    case NOTA = 'NOTA';

    public function mudaEstado(): bool
    {
        return $this !== self::NOTA;
    }
}
