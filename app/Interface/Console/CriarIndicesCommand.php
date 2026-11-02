<?php

namespace App\Interface\Console;

use App\Infrastructure\Persistence\Mongo\MongoMensagensProcessadasRepository;
use App\Infrastructure\Persistence\Mongo\MongoTimelineRepository;
use Illuminate\Console\Command;

class CriarIndicesCommand extends Command
{
    protected $signature = 'execucao:criar-indices';

    protected $description = 'Cria os índices do MongoDB: timeline_os e mensagens_processadas (idempotente)';

    public function handle(MongoTimelineRepository $timeline, MongoMensagensProcessadasRepository $mensagens): int
    {
        $timeline->criarIndices();
        $this->info('Índices de timeline_os criados.');

        $mensagens->criarIndices();
        $this->info('Índice de mensagens_processadas criado.');

        return self::SUCCESS;
    }
}
