<?php

namespace Tests\Support;

use App\Domain\Execucao\Repositories\MensagemProcessadaRepositoryInterface;

/** Fake em memória do registro de messageId (mesma regra do Mongo: o id é único). */
class MensagensProcessadasEmMemoria implements MensagemProcessadaRepositoryInterface
{
    /** @var array<string, true> */
    private array $ids = [];

    public function marcarSeNova(string $messageId): bool
    {
        if (isset($this->ids[$messageId])) {
            return false;
        }

        $this->ids[$messageId] = true;

        return true;
    }

    public function liberar(string $messageId): void
    {
        unset($this->ids[$messageId]);
    }
}
