<?php

namespace App\Interface\Console;

use App\Infrastructure\Messaging\RabbitMq\ConsumidorDeComandos;
use Illuminate\Console\Command;

class ConsumirComandosCommand extends Command
{
    protected $signature = 'execucao:consumir
                            {--max= : para depois de tratar N mensagens}
                            {--ocioso= : para depois de N segundos sem mensagem}';

    protected $description = 'Consome os comandos execucao.enviar-para-execucao do RabbitMQ';

    public function handle(ConsumidorDeComandos $consumidor): int
    {
        $this->info('Consumindo ' . config('rabbitmq.fila_enviar') . '...');

        $tratadas = $consumidor->executar(
            $this->option('max') !== null ? (int) $this->option('max') : null,
            $this->option('ocioso') !== null ? (float) $this->option('ocioso') : null,
        );

        $this->info("Mensagens tratadas: {$tratadas}");

        return self::SUCCESS;
    }
}
