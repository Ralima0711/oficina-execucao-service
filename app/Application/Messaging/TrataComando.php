<?php

namespace App\Application\Messaging;

/**
 * Caso de uso de um comando do orquestrador, um por fila de comando. Roda dentro do span
 * CONSUMER aberto por MessageTracing::consumir; o consumidor decide o ack/nack:
 *
 *   - retorna                  → ack
 *   - lança MensagemInvalida   → nack sem requeue (DLQ): repetir não adianta
 *   - lança qualquer outra     → nack sem requeue (DLQ), depois de liberar o messageId
 */
interface TrataComando
{
    /** @throws MensagemInvalida corpo fora do contrato */
    public function handle(Envelope $envelope): void;
}
