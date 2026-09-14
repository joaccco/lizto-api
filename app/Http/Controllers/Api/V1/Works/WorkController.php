<?php

namespace App\Http\Controllers\Api\V1\Works;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\RatingModel;
use App\Infrastructure\Persistence\Eloquent\WorkEventModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkController extends Controller
{
    use \App\Traits\ResolvesByUuid;

    public function complete(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);

        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('complete', $work);

        $currentEnum = $work->status instanceof \App\Domain\Works\Enums\WorkStatus
            ? $work->status
            : \App\Domain\Works\Enums\WorkStatus::from($work->status);

        if (!$currentEnum->canTransitionTo(\App\Domain\Works\Enums\WorkStatus::Completed)) {
            throw new \App\Domain\Shared\Exceptions\InvalidStateTransitionException(
                "No se puede cambiar el estado de {$currentEnum->value} a " . \App\Domain\Works\Enums\WorkStatus::Completed->value . "."
            );
        }

        \App\Domain\Works\Policies\WorkCompletionPolicy::validateCanComplete($work);

        $work->transitionTo(\App\Domain\Works\Enums\WorkStatus::Completed);
        $work->update([
            'completed_at' => now(),
        ]);

        if ($work->provider) {
            $work->provider->increment('total_jobs_completed');
        }

        return response()->json([
            'message' => 'El trabajo fue marcado como completado.',
            'data' => [
                'id' => $work->uuid,
                'status' => 'completed',
            ],
        ]);
    }

    public function cancel(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);

        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('cancel', $work);

        $user = $request->user();
        $reason = $request->input('reason', 'Cancelado por el usuario');

        $work->transitionTo(\App\Domain\Works\Enums\WorkStatus::Cancelled);

        $isProvider = $work->provider && (int) $work->provider->user_id === (int) $user->id;
        $cancelledBy = $isProvider ? 'provider' : 'client';

        if ($isProvider && $work->provider) {
            $work->provider->increment('cancellation_count');
        }

        WorkEventModel::create([
            'work_id' => $work->id,
            'event_type' => 'cancelled',
            'actor_id' => $user->id,
            'metadata' => [
                'cancelled_by' => $cancelledBy,
                'reason' => $reason,
            ],
        ]);

        return response()->json([
            'message' => 'Trabajo cancelado correctamente.',
            'data' => [
                'id' => $work->uuid,
                'status' => 'cancelled',
                'reason' => $reason,
                'cancelled_by' => $cancelledBy,
            ],
        ]);
    }

    public function rate(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);
        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        $user = $request->user();

        \Illuminate\Support\Facades\Gate::authorize('rate', $work);

        if ($work->client_id !== $user->id) {
            return response()->json(['message' => 'Solo el cliente contratante puede calificar este trabajo.'], 403);
        }

        $statusVal = $work->status instanceof \BackedEnum ? $work->status->value : $work->status;
        if ($statusVal !== 'completed') {
            return response()->json(['message' => 'Solo se pueden calificar trabajos completados.'], 422);
        }

        $existing = RatingModel::where('work_id', $work->id)
            ->where('reviewer_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Ya calificaste este trabajo.'], 422);
        }

        $validated = $request->validate([
            'score' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $providerProfile = ProviderProfileModel::find($work->provider_id);
        $providerUserId = $providerProfile?->user_id ?? $work->provider_id;

        $rating = DB::transaction(function () use ($work, $user, $providerUserId, $providerProfile, $validated) {
            $r = RatingModel::create([
                'work_id' => $work->id,
                'reviewer_id' => $user->id,
                'reviewed_id' => $providerUserId,
                'direction' => 'client_to_provider',
                'score' => $validated['score'],
                'comment' => $validated['comment'] ?? null,
            ]);

            if ($providerProfile) {
                $allRatings = RatingModel::where('reviewed_id', $providerUserId)->get();
                $count = $allRatings->count();
                $avg = $count > 0 ? round($allRatings->avg('score'), 2) : 0;

                $providerProfile->update([
                    'avg_rating' => $avg,
                    'total_reviews' => $count,
                ]);
            }

            return $r;
        });

        event(new \App\Domain\Ratings\Events\ReviewSubmitted($rating));

        return response()->json([
            'message' => 'Calificación enviada con éxito.',
            'data' => [
                'id' => $rating->id,
                'score' => $rating->score,
                'comment' => $rating->comment,
            ],
        ], 200);
    }

    public function location(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);
        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        $user = $request->user();
        $providerProfile = $user?->providerProfile;
        $isClient = $user && (int) $work->client_id === (int) $user->id;
        $isProvider = $providerProfile && (int) $work->provider_id === (int) $providerProfile->id;

        if (!$isClient && !$isProvider) {
            return response()->json(['message' => 'No autorizado para ver la ubicación de este trabajo.'], 403);
        }

        $serviceRequest = $work->serviceRequest;
        if (!$serviceRequest) {
            return response()->json(['message' => 'Solicitud no encontrada.'], 404);
        }

        $locationData = \App\Domain\Location\Services\LocationPresenter::present($serviceRequest, $user);

        return response()->json([
            'data' => $locationData,
        ], 200);
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);
        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        $validated = $request->validate([
            'scheduled_at' => 'nullable|date',
            'estimated_duration_min' => 'nullable|integer|min:15|max:480',
        ]);

        $work->update($validated);

        return response()->json([
            'message' => 'Trabajo actualizado correctamente.',
            'data' => [
                'id' => $work->uuid,
                'status' => $work->status instanceof \BackedEnum ? $work->status->value : $work->status,
            ],
        ], 200);
    }

    public function submitFinalQuote(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);
        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        $providerProfile = ProviderProfileModel::where('user_id', $request->user()->id)->first();
        if (!$providerProfile || !$providerProfile->isIdentityVerified()) {
            return response()->json(['message' => 'Solo los profesionales con identidad verificada pueden enviar presupuestos finales.'], 403);
        }

        \Illuminate\Support\Facades\Gate::authorize('submitFinalQuote', $work);

        $hasAcceptedQuote = \App\Infrastructure\Persistence\Eloquent\WorkQuoteModel::where('work_id', $work->id)
            ->where('status', 'accepted')
            ->exists();

        if ($hasAcceptedQuote) {
            return response()->json(['message' => 'El trabajo ya posee un presupuesto aceptado.'], 422);
        }

        $validated = $request->validate([
            'final_price' => 'required|numeric|min:0',
        ]);

        $work->update([
            'final_price' => $validated['final_price'],
        ]);

        event(new \App\Domain\Works\Events\FinalQuoteSubmitted($work));

        return response()->json([
            'message' => 'Presupuesto final enviado.',
            'data' => [
                'id' => $work->uuid,
                'status' => $work->status->value,
                'final_price' => $work->final_price,
            ],
        ]);
    }

    public function confirmFinalQuote(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);
        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('confirmFinalQuote', $work);

        $hasAcceptedQuote = \App\Infrastructure\Persistence\Eloquent\WorkQuoteModel::where('work_id', $work->id)
            ->where('status', 'accepted')
            ->exists();

        if ($hasAcceptedQuote) {
            return response()->json(['message' => 'El trabajo ya posee un presupuesto aceptado.'], 422);
        }

        if (empty($work->final_price) || $work->final_price <= 0) {
            return response()->json(['message' => 'No hay un presupuesto final cargado para confirmar.'], 422);
        }

        $quote = \App\Infrastructure\Persistence\Eloquent\WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $work->provider_id,
            'client_id' => $work->client_id,
            'amount' => $work->final_price,
            'currency' => $work->currency ?? 'ARS',
            'terms_conditions' => 'Presupuesto final de diagnóstico presencial confirmado',
            'origin' => 'final_quote_confirmation',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        app(\App\Application\Works\Services\WorkScheduleValidator::class)->validateNoOverlap(
            $work->provider_id,
            $work->scheduled_at,
            $work->estimated_duration_min,
            $work->id
        );

        $work->applyAcceptedQuote($quote);
        $work->transitionTo(\App\Domain\Works\Enums\WorkStatus::Confirmed);

        event(new \App\Domain\Works\Events\FinalQuoteConfirmed($work));

        return response()->json([
            'message' => 'Presupuesto final confirmado.',
            'data' => [
                'id' => $work->uuid,
                'status' => $work->status->value,
                'agreed_price' => $work->agreed_price,
            ],
        ]);
    }

    public function rejectFinalQuote(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);
        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('rejectFinalQuote', $work);

        $work->transitionTo(\App\Domain\Works\Enums\WorkStatus::Cancelled);

        event(new \App\Domain\Works\Events\FinalQuoteRejected($work));

        return response()->json([
            'message' => 'Presupuesto final rechazado. El trabajo ha sido cancelado.',
            'data' => [
                'id' => $work->uuid,
                'status' => $work->status->value,
            ],
        ]);
    }

    public function progress(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);
        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        $work->load(['provider.user', 'conversation']);
        $provider = $work->provider;
        $providerUser = $provider?->user;
        $statusVal = $work->status instanceof \BackedEnum ? $work->status->value : $work->status;

        $providerName = $providerUser?->name ?? 'El profesional';
        $nextStepDescription = match ($statusVal) {
            'pending_diagnosis_quote' => $work->final_price
                ? "Esperando que confirmes el precio final cotizado por {$providerName}."
                : "{$providerName} está evaluando el trabajo presencialmente.",
            'confirmed', 'scheduled' => "Trabajo agendado y confirmado.",
            'in_progress' => "Trabajo en curso.",
            'completed' => "Trabajo completado.",
            'cancelled' => "Trabajo cancelado.",
            default => "En proceso.",
        };

        return response()->json([
            'data' => [
                'work_id' => $work->uuid,
                'status' => $statusVal,
                'agreed_price' => (float) $work->agreed_price,
                'final_price' => $work->final_price ? (float) $work->final_price : null,
                'currency' => $work->currency ?? 'ARS',
                'provider' => [
                    'name' => $providerUser?->name ?? 'Proveedor',
                    'avatar_url' => $providerUser?->avatar_url,
                    'avg_rating' => ($provider?->total_reviews ?? 0) > 0 ? (float) $provider->avg_rating : null,
                ],
                'conversation_id' => $work->conversation?->uuid,
                'next_step_description' => $nextStepDescription,
            ],
        ]);
    }
}
