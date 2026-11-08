<?php

namespace App\Domain\Execucao\ValueObjects;

/**
 * CAMADA DE DOMÍNIO — Value Object
 *
 * Etapas do ciclo de vida da OS que passam por este serviço. Os nomes seguem o
 * StatusOrdem do OS Service; o status completo da OS continua sendo dele.
 *
 *   RECEBIDA → EM_DIAGNOSTICO → (orçamento/aprovação, fora daqui) → APROVADA
 *            → EM_EXECUCAO → FINALIZADA
 *
 *   APROVADA (na fila) ou EM_EXECUCAO → FALHOU   (falha do reparo ou CancelarExecucao)
 */
enum StatusExecucao: string
{
    case RECEBIDA = 'RECEBIDA';
    case EM_DIAGNOSTICO = 'EM_DIAGNOSTICO';
    case APROVADA = 'APROVADA';
    case EM_EXECUCAO = 'EM_EXECUCAO';
    case FINALIZADA = 'FINALIZADA';
    /** Terminal: reparo que falhou ou execução cancelada pela compensação da saga. */
    case FALHOU = 'FALHOU';
}
