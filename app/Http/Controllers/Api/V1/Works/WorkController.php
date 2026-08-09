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
    public function complete(string $id, Request $request): JsonResponse
    {
        $work = WorkModel::where('uuid', $id)->first();
        if (!$work) {
            $work = WorkModel::where('id', $id)->first();
        }

        $serviceRequest = ServiceRequestModel::where('uuid', $id)->first();
        if ($serviceRequest) {
            $serviceRequest->update(['status' => 'completed']);
        }

        if ($work) {
            $work->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

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

        $work = WorkModel::where('uuid', $id)->orWhere('id', $id)->first();
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->first();

        if ($serviceRequest) {
            $serviceRequest->update(['status' => 'cancelled']);
        }

        if ($work) {
            $work->update([
                'status' => 'cancelled',
            ]);
            if ($work->serviceRequest) {
                $work->serviceRequest->update(['status' => 'cancelled']);
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

        $work = WorkModel::where('uuid', $workId)->orWhere('id', $workId)->first();
        $serviceRequest = ServiceRequestModel::where('uuid', $workId)->first();

        $providerId = null;
        $providerProfile = null;

        if ($work && $work->provider) {
            $providerProfile = $work->provider;
            $providerId = $work->provider->user_id;
        } elseif ($serviceRequest && $serviceRequest->accepted_provider_id) {
            $providerProfile = ProviderProfileModel::find($serviceRequest->accepted_provider_id);
            if ($providerProfile) {
                $providerId = $providerProfile->user_id;
            }
        }

        if (!$providerId && $providerProfile) {
            $providerId = $providerProfile->user_id;
        }

        $rating = RatingModel::create([
            'work_id' => $work ? $work->id : null,
            'reviewer_id' => $user->id,
            'reviewed_id' => $providerId ?: 2, // fallback
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
