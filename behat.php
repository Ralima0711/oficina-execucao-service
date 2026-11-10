<?php

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Tests\Bdd\ExecucaoContext;

/*
 * BDD da Execução: o topo da pirâmide (docs/guia-testes-cobertura.md). Roda contra o
 * RabbitMQ, o Redis e o MongoDB do compose, com filas e bancos próprios (sufixo .bdd):
 *
 *   docker compose exec app composer bdd
 */
return (new Config())
    ->withProfile(
        (new Profile('default'))
            ->withSuite(
                (new Suite('execucao'))
                    ->withPaths('%paths.base%/features')
                    ->withContexts(ExecucaoContext::class)
            )
    );
