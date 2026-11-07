<?php

namespace App\Domain\Execucao\Repositories;

/**
 * Registro local dos messageId já tratados (contrato v1.0, regra 1 da Parte 5).
 * Cada serviço guarda os seus no próprio banco; não existe store compartilhado.
 *
 * Uso esperado do consumidor:
 *
 *   if (!$mensagens->marcarSeNova($messageId)) { return; }   // repetição: descarta
 *   try { ...efeitos... } catch (\Throwable $e) { $mensagens->liberar($messageId); throw $e; }
 *
 * Marcar antes dos efeitos fecha a janela em que duas entregas simultâneas da
 * mesma mensagem passariam juntas; liberar em caso de falha deixa o retry tratar.
 */
interface MensagemProcessadaRepositoryInterface
{
    /** Registra o messageId. Devolve false se ele já estava registrado (mensagem repetida). */
    public function marcarSeNova(string $messageId): bool;

    /** Remove o registro, para uma mensagem que falhou poder ser reprocessada. */
    public function liberar(string $messageId): void;
}
