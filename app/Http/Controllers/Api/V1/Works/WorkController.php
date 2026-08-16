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
        $serviceRequest = $this->findByUuid(ServiceRequestModel::class, $id);

        if (!$work && !$serviceRequest) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        if ($work) {
            \Illuminate\Support\Facades\Gate::authorize('complete', $work);
        } elseif ($serviceRequest) {
            \Illuminate\Support\Facades\Gate::authorize('view', $serviceRequest);
        }

        if ($serviceRequest) {
            $serviceRequest->transitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::Completed);
        }

        if ($work) {
            $work->transitionTo(\App\Domain\Works\Enums\WorkStatus::Completed);

            if ($work->provider) {
                $work->provider->update(['availability_status' => 'available']);
            }
        } else {
            // Also update provider profile if user is provider
            $user = $request->user();
            $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
            if ($providerProfile) {
                $providerProfile->update(['availability_status' => 'available']);
            }
        }

        return response()->json([
            'message' => 'El trabajo fue marcado como completado.',
            'data' => [
                'id' => $id,
                'status' => 'completed',
            ],
        ]);
    }

    public function cancel(string $id, Request $request): JsonResponse
    {
        $reason = $request->input('reason', 'No especificado');

        $work = $this->findByUuid(WorkModel::class, $id);
        $serviceRequest = $this->findByUuid(ServiceRequestModel::class, $id);

        if (!$work && !$serviceRequest) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        if ($work) {
            \Illuminate\Support\Facades\Gate::authorize('cancel', $work);
        } elseif ($serviceRequest) {
            \Illuminate\Support\Facades\Gate::authorize('cancel', $serviceRequest);
        }

        if ($serviceRequest) {
            $serviceRequest->transitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::Cancelled);
        }

        if ($work) {
            $work->transitionTo(\App\Domain\Works\Enums\WorkStatus::Cancelled);
            if ($work->serviceRequest && $work->serviceRequest->status !== \App\Domain\ServiceRequests\Enums\RequestStatus::Cancelled) {
                $work->serviceRequest->transitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::Cancelled);
            }
            if ($work->provider) {
                $work->provider->update(['availability_status' => 'available']);
            }
        }

        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if ($providerProfile) {
            $providerProfile->update(['availability_status' => 'available']);
        }

        return response()->json([
            'message' => 'Trabajo cancelado.',
            'data' => [
                'id' => $id,
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
        $serviceRequest = $this->findByUuid(ServiceRequestModel::class, $workId);

        if (!$work && !$serviceRequest) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        if ($work) {
            \Illuminate\Support\Facades\Gate::authorize('rate', $work);
        } elseif ($serviceRequest) {
            \Illuminate\Support\Facades\Gate::authorize('cancel', $serviceRequest);
        }

        $workStatus = $work ? ($work->status instanceof \BackedEnum ? $work->status->value : $work->status)
            : ($serviceRequest ? ($serviceRequest->status instanceof \BackedEnum ? $serviceRequest->status->value : $serviceRequest->status) : null);

        if ($workStatus !== 'completed') {
            return response()->json(['message' => 'Solo se pueden calificar trabajos completados.'], 422);
        }

        $providerId = null;
        $providerProfile = null;

        if ($work && $work->provider) {
            $providerProfile = $work->provider;
            $providerId = $work->provider->user_id;
        }

        if (!$providerId) {
            return response()->json(['message' => 'No se pudo identificar el profesional a calificar.'], 422);
        }

        $existingRating = RatingModel::where('work_id', $work ? $work->id : null)
            ->where('reviewer_id', $user->id)
            ->where('direction', 'client_to_provider')
            ->first();

        if ($existingRating) {
            return response()->json(['message' => 'Ya calificaste este trabajo.'], 422);
        }

        $rating = RatingModel::create([
            'work_id' => $work ? $work->id : null,
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
}
