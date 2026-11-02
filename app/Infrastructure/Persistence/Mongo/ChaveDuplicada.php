<?php

namespace App\Infrastructure\Persistence\Mongo;

use MongoDB\Driver\Exception\Exception as MongoException;

/** Reconhece a violação de índice único do MongoDB (E11000). */
final class ChaveDuplicada
{
    public const CODIGO = 11000;

    public static function e(\Throwable $e): bool
    {
        return $e instanceof MongoException && $e->getCode() === self::CODIGO;
    }
}
