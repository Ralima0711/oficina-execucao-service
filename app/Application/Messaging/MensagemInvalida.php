<?php

namespace App\Application\Messaging;

/**
 * A mensagem recebida não respeita o contrato (JSON quebrado, campo obrigatório
 * ausente, payload inválido). Repetir não adianta: vai para a DLQ.
 */
class MensagemInvalida extends \InvalidArgumentException
{
}
