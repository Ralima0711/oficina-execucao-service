<?php

namespace Tests\Support;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\FalhaAoPublicarEvento;
use App\Application\Messaging\PublicadorDeEventosInterface;

/** Fake do publicador: guarda o que seria enviado ao broker. */
class PublicadorEmMemoria implements PublicadorDeEventosInterface
{
    /** @var array<int, array{routingKey: string, envelope: Envelope}> */
    public array $publicadas = [];

    public bool $falhar = false;

    public function publicar(string $routingKey, Envelope $envelope): void
    {
        if ($this->falhar) {
            throw new FalhaAoPublicarEvento('broker fora do ar (simulado)');
        }

        $this->publicadas[] = ['routingKey' => $routingKey, 'envelope' => $envelope];
    }

    /** @return string[] */
    public function routingKeys(): array
    {
        return array_column($this->publicadas, 'routingKey');
    }
}
