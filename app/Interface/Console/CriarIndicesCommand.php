<?php

namespace App\Interface\Console;

use App\Infrastructure\Persistence\Mongo\MongoTimelineRepository;
use Illuminate\Console\Command;

class CriarIndicesCommand extends Command
{
    protected $signature = 'execucao:criar-indices';

    protected $description = 'Cria os índices da coleção timeline_os no MongoDB (idempotente)';

    public function handle(MongoTimelineRepository $timeline): int
    {
        $timeline->criarIndices();

        $this->info('Índices de timeline_os criados.');

        return self::SUCCESS;
    }
}
