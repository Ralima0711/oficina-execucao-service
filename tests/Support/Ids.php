<?php

namespace Tests\Support;

/** UUIDs determinísticos para os testes: Ids::os(10) é sempre o mesmo valor. */
final class Ids
{
    public static function os(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    public static function mecanico(int $n): string
    {
        return sprintf('00000000-0000-4000-9000-%012d', $n);
    }
}
