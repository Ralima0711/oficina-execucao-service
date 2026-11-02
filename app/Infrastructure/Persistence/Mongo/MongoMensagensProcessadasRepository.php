<?php

namespace App\Infrastructure\Persistence\Mongo;

use App\Domain\Execucao\Repositories\MensagemProcessadaRepositoryInterface;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;

/**
 * Coleção `mensagens_processadas`: o messageId é o próprio `_id`, então o Mongo
 * garante a unicidade e duas entregas simultâneas não passam juntas. Um índice TTL
 * em `processadaEm` apaga os registros antigos.
 */
class MongoMensagensProcessadasRepository implements MensagemProcessadaRepositoryInterface
{
    public const COLECAO = 'mensagens_processadas';

    public function __construct(private Database $database) {}

    public function marcarSeNova(string $messageId): bool
    {
        try {
            $this->colecao()->insertOne(['_id' => $messageId, 'processadaEm' => new UTCDateTime()]);
        } catch (\Throwable $e) {
            if (ChaveDuplicada::e($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function liberar(string $messageId): void
    {
        $this->colecao()->deleteOne(['_id' => $messageId]);
    }

    /** Cria o índice TTL (idempotente). */
    public function criarIndices(): void
    {
        $this->colecao()->createIndex(
            ['processadaEm' => 1],
            [
                'name'               => 'processada_ttl',
                'expireAfterSeconds' => (int) config('mongodb.mensagens_processadas_ttl_dias') * 86400,
            ]
        );
    }

    private function colecao(): Collection
    {
        return $this->database->selectCollection(self::COLECAO);
    }
}
