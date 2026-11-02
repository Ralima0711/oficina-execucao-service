<?php

namespace App\Application\Messaging;

/** O broker não aceitou a mensagem (fora do ar, nack ou sem fila ligada à routing key). */
class FalhaAoPublicarEvento extends \RuntimeException
{
}
