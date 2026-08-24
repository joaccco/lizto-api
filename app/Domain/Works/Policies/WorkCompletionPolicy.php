<?php

namespace App\Domain\Works\Policies;

use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Validation\ValidationException;

class WorkCompletionPolicy
{
    public static function validateCanComplete(WorkModel $work): void
    {
        $hasAcceptedQuote = $work->quotes()->where('status', 'accepted')->exists() || (bool) $work->is_legacy_pre_quote;

        if (!$hasAcceptedQuote) {
            throw ValidationException::withMessages([
                'work' => ['No se puede finalizar el trabajo sin un presupuesto aceptado por el cliente.'],
            ]);
        }
    }
}
