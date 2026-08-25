<?php

namespace App\Application\Works\Services;

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkScheduleValidator
{
    /**
     * Check if a provider has a schedule conflict for a proposed start time and duration.
     *
     * @throws ValidationException
     */
    public function validateNoOverlap(int $providerId, mixed $scheduledAt, ?int $durationMin = 60, ?int $ignoreWorkId = null): void
    {
        if ($scheduledAt === null) {
            return;
        }

        if ($scheduledAt instanceof \Carbon\CarbonInterface) {
            $startUtc = $scheduledAt->copy()->utc();
        } else {
            $startUtc = \Carbon\Carbon::parse((string) $scheduledAt, 'America/Argentina/Buenos_Aires')->utc();
        }
        $duration = $durationMin ?? 60;
        $endsUtc = $startUtc->copy()->addMinutes((int) $duration);

        $startUtcStr = $startUtc->toIso8601String();
        $endsUtcStr = $endsUtc->toIso8601String();

        $check = function () use ($providerId, $startUtc, $endsUtc, $startUtcStr, $ignoreWorkId) {
            // Lock provider profile to serialize work creation for this provider
            ProviderProfileModel::where('id', $providerId)->lockForUpdate()->first();

            return WorkModel::where('provider_id', $providerId)
                ->when($ignoreWorkId, fn($q) => $q->where('id', '!=', $ignoreWorkId))
                ->whereIn('status', [
                    \App\Domain\Works\Enums\WorkStatus::Confirmed,
                    \App\Domain\Works\Enums\WorkStatus::InProgress,
                    \App\Domain\Works\Enums\WorkStatus::Confirmed->value,
                    \App\Domain\Works\Enums\WorkStatus::InProgress->value,
                ])
                ->whereNotNull('scheduled_at')
                ->where(function ($wq) use ($startUtc, $endsUtc, $startUtcStr) {
                    $wq->where('scheduled_at', '<', $endsUtc)
                       ->where(function ($sq) use ($startUtc, $startUtcStr) {
                           $sq->where('scheduled_ends_at', '>', $startUtc)
                              ->orWhere(function ($rawQ) use ($startUtcStr) {
                                  $rawQ->whereNull('scheduled_ends_at')
                                       ->whereRaw("scheduled_at + (COALESCE(estimated_duration_min, 60) || ' minutes')::interval > ?", [$startUtcStr]);
                              });
                       });
                })
                ->exists();
        };

        $conflictExists = DB::transactionLevel() > 0 ? $check() : DB::transaction($check);

        if ($conflictExists) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['El profesional ya cuenta con un trabajo agendado en esa franja horaria.'],
            ]);
        }
    }
}
