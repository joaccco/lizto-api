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

        if ($work->status === \App\Domain\Works\Enums\WorkStatus::PendingDiagnosisQuote || $work->status === \App\Domain\Works\Enums\WorkStatus::PendingDiagnosisQuote->value) {
            if ($work->estimated_duration_min === null) {
                throw ValidationException::withMessages([
                    'work' => ['No se puede completar el trabajo sin una duración estimada definida.'],
                ]);
            }

            if ($work->work_lat === null || $work->work_lng === null) {
                throw ValidationException::withMessages([
                    'work' => ['No se puede completar el trabajo sin información de ubicación válida.'],
                ]);
            }

            if (empty($work->work_address)) {
                throw ValidationException::withMessages([
                    'work' => ['No se puede completar el trabajo sin dirección definida.'],
                ]);
            }
        }
    }
}
