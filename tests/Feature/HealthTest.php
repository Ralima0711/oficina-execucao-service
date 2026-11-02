<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HealthTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function caminhos(): array
    {
        return [
            // O que a readiness e a liveness do Kubernetes chamam.
            '/health' => ['/health'],
            // O caminho anterior, que continua respondendo.
            '/api/health' => ['/api/health'],
        ];
    }

    #[DataProvider('caminhos')]
    public function test_a_saude_responde_200(string $caminho): void
    {
        $this->getJson($caminho)->assertOk()->assertExactJson(['status' => 'ok']);
    }
}
