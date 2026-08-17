<?php

namespace App\Http\Controllers\Api\V1\Works;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\RatingModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $workStatus = $work->status instanceof \BackedEnum ? $work->status->value : $work->status;
        if ($workStatus === 'completed') {
            return response()->json([
                'message' => 'El trabajo fue marcado como completado.',
                'data' => [
                    'id' => $work->uuid,
                    'status' => 'completed',
                ],
            ]);
        }

        $work->transitionTo(\App\Domain\Works\Enums\WorkStatus::Completed);

        if ($work->serviceRequest && $work->serviceRequest->status !== \App\Domain\ServiceRequests\Enums\RequestStatus::Completed) {
            $work->serviceRequest->transitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::Completed);
        }

        if ($work->provider) {
            $work->provider->update(['availability_status' => 'available']);
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
        $reason = $request->input('reason', 'No especificado');

        $work = $this->findByUuid(WorkModel::class, $id);

        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('cancel', $work);

        $work->transitionTo(\App\Domain\Works\Enums\WorkStatus::Cancelled);
        if ($work->serviceRequest && $work->serviceRequest->status !== \App\Domain\ServiceRequests\Enums\RequestStatus::Cancelled) {
            $work->serviceRequest->transitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::Cancelled);
        }
        if ($work->provider) {
            $work->provider->update(['availability_status' => 'available']);
        }

        return response()->json([
            'message' => 'Trabajo cancelado.',
            'data' => [
                'id' => $work->uuid,
                'status' => 'cancelled',
                'reason' => $reason,
            ],
        ]);
    }

    public function rate(string $workId, Request $request): JsonResponse
    {
        $request->validate([
            'score' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        $work = $this->findByUuid(WorkModel::class, $workId);

        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('rate', $work);

        $workStatus = $work->status instanceof \BackedEnum ? $work->status->value : $work->status;

        if ($workStatus !== 'completed') {
            return response()->json(['message' => 'Solo se pueden calificar trabajos completados.'], 422);
        }

        $providerProfile = $work->provider;
        $providerId = $providerProfile?->user_id;

        if (!$providerId) {
            return response()->json(['message' => 'No se pudo identificar el profesional a calificar.'], 422);
        }

        $existingRating = RatingModel::where('work_id', $work->id)
            ->where('reviewer_id', $user->id)
            ->where('direction', 'client_to_provider')
            ->first();

        if ($existingRating) {
            return response()->json(['message' => 'Ya calificaste este trabajo.'], 422);
        }

        $rating = RatingModel::create([
            'work_id' => $work->id,
            'reviewer_id' => $user->id,
            'reviewed_id' => $providerId,
            'direction' => 'client_to_provider',
            'score' => $request->input('score'),
            'comment' => $request->input('comment'),
            'created_at' => now(),
        ]);

        if ($providerProfile) {
            $avg = RatingModel::where('reviewed_id', $providerProfile->user_id)->avg('score') ?: $request->input('score');
            $count = RatingModel::where('reviewed_id', $providerProfile->user_id)->count();

            $providerProfile->update([
                'avg_rating' => round($avg, 2),
                'total_reviews' => $count,
            ]);
        }

        return response()->json([
            'message' => 'Calificación enviada con éxito.',
            'data' => $rating,
        ]);
    }

    public function submitFinalQuote(string $id, Request $request): JsonResponse
    {
        $work = $this->findByUuid(WorkModel::class, $id);
        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
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

        if (empty($work->final_price) || $work->final_price <= 0) {
            return response()->json(['message' => 'No hay un presupuesto final cargado para confirmar.'], 422);
        }

        $work->transitionTo(\App\Domain\Works\Enums\WorkStatus::Confirmed);
        $work->update([
            'agreed_price' => $work->final_price,
        ]);

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
                    'avg_rating' => (float) ($provider?->avg_rating ?? 5.0),
                ],
                'conversation_id' => $work->conversation?->uuid,
                'next_step_description' => $nextStepDescription,
            ],
        ]);
    }
}
