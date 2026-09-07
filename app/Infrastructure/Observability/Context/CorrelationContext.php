<?php

namespace App\Infrastructure\Observability\Context;

use Illuminate\Support\Str;

class CorrelationContext
{
    private static ?string $correlationId = null;

    public static function set(?string $id): void
    {
        self::$correlationId = $id;
    }

    public static function get(): string
    {
        if (empty(self::$correlationId)) {
            self::$correlationId = (string) Str::uuid();
        }

        return self::$correlationId;
    }

    public static function reset(): void
    {
        self::$correlationId = null;
    }
}
