<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // O LOG_CHANNEL do ambiente (stdout_json no compose) vence o phpunit.xml:
        // sem isto os testes despejam JSON de log na saída.
        config(['logging.default' => 'null']);
    }
}
