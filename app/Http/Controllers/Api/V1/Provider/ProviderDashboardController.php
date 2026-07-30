<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderDashboardController extends Controller
{
    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:available,busy,unavailable',
        ]);

        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if ($providerProfile) {
            $providerProfile->update([
                'availability_status' => $request->status,
            ]);
        }

        return response()->json([
            'data' => [
                'status' => $request->status,
            ],
            'message' => 'Disponibilidad actualizada.',
        ]);
    }

    public function workRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if (!$providerProfile) {
            return response()->json(['data' => []]);
        }

        $categoryIds = $providerProfile->categories()->pluck('category_id')->filter()->toArray();

        $requests = ServiceRequestModel::query()
            ->where(function ($query) use ($providerProfile, $categoryIds) {
                $query->whereHas('matchSession.cards', function ($q) use ($providerProfile) {
                    $q->where('provider_id', $providerProfile->id);
                });
                if (!empty($categoryIds)) {
                    $query->orWhereIn('category_id', $categoryIds);
                }
            })
            ->with(['category', 'client'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $items = $requests->map(function ($sr) {
            return [
                'id' => $sr->uuid,
                'category' => $sr->category ? $sr->category->name : 'Servicio general',
                'category_slug' => $sr->category ? $sr->category->slug : 'general',
                'raw_prompt' => $sr->raw_prompt,
                'client_name' => $sr->client ? explode(' ', $sr->client->name)[0] : 'Cliente',
                'urgency' => $sr->urgency instanceof \BackedEnum ? $sr->urgency->value : $sr->urgency,
                'location' => 'Centro',
                'status' => $sr->status instanceof \BackedEnum ? $sr->status->value : $sr->status,
                'created_at' => $sr->created_at?->toISOString(),
            ];
        });

        return response()->json(['data' => $items]);
    }

    public function confirmWorkRequest(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'estimated_duration_min' => 'nullable|integer',
        ]);

        $serviceRequest = ServiceRequestModel::where('uuid', $id)->first();
        if ($serviceRequest) {
            $serviceRequest->update(['status' => 'confirmed']);
        }

        return response()->json([
            'message' => 'Trabajo confirmado.',
            'data' => ['id' => $id, 'status' => 'confirmed'],
        ]);
    }

    public function declineWorkRequest(string $id): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->first();
        if ($serviceRequest) {
            $serviceRequest->update(['status' => 'cancelled']);
        }

        return response()->json([
            'message' => 'Solicitud declinada.',
            'data' => ['id' => $id, 'status' => 'declined'],
        ]);
    }
}
