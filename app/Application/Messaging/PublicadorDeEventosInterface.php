<?php

namespace App\Application\Messaging;

interface PublicadorDeEventosInterface
{
    /**
     * Publica o envelope no exchange de eventos com a routing key dada.
     *
     * @throws FalhaAoPublicarEvento se o broker não confirmar a mensagem
     */
    public function publicar(string $routingKey, Envelope $envelope): void;
}
