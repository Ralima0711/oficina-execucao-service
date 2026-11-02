<?php

namespace App\Infrastructure\Providers;

use App\Application\Messaging\PublicadorDeEventosInterface;
use App\Domain\Execucao\Repositories\FilaExecucaoRepositoryInterface;
use App\Domain\Execucao\Repositories\MensagemProcessadaRepositoryInterface;
use App\Domain\Execucao\Repositories\TimelineRepositoryInterface;
use App\Infrastructure\Messaging\RabbitMq\ConexaoRabbitMq;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqPublicador;
use App\Infrastructure\Persistence\Mongo\MongoMensagensProcessadasRepository;
use App\Infrastructure\Persistence\Mongo\MongoTimelineRepository;
use App\Infrastructure\Persistence\Redis\RedisFilaExecucaoRepository;
use Illuminate\Support\ServiceProvider;
use MongoDB\Client;
use MongoDB\Database;


class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Database::class, function () {
            $client = new Client(
                (string) config('mongodb.uri'),
                ['serverSelectionTimeoutMS' => (int) config('mongodb.server_selection_timeout_ms')]
            );

            return $client->selectDatabase((string) config('mongodb.database'));
        });

        $this->app->bind(TimelineRepositoryInterface::class, MongoTimelineRepository::class);
        $this->app->bind(MensagemProcessadaRepositoryInterface::class, MongoMensagensProcessadasRepository::class);
        $this->app->bind(FilaExecucaoRepositoryInterface::class, RedisFilaExecucaoRepository::class);

        // Conecta ao RabbitMQ só ao publicar: subir a API não exige o broker no ar.
        $this->app->bind(PublicadorDeEventosInterface::class, fn () => new RabbitMqPublicador(new ConexaoRabbitMq()));
    }
}
