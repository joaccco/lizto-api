<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait ResolvesByUuid
{
    /**
     * Resolve an Eloquent model strictly by its 'uuid' column.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @param string $uuid
     * @return T|null
     */
    protected function findByUuid(string $modelClass, string $uuid): ?Model
    {
        if (!Str::isUuid($uuid)) {
            return null;
        }

        return $modelClass::where('uuid', $uuid)->first();
    }
}
