<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)
            ->with(['user', 'categories.category', 'serviceAreas', 'schedules'])
            ->first();

        if (!$providerProfile) {
            return response()->json([
                'data' => [
                    'id' => null,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone ?? '',
                    'bio' => '',
                    'radius_km' => 15,
                    'specialties' => [],
                    'schedules' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $providerProfile->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'bio' => $providerProfile->bio ?? '',
                'years_experience' => $providerProfile->years_experience ?? 3,
                'is_verified' => $providerProfile->is_verified ?? true,
                'avg_rating' => (float) ($providerProfile->avg_rating ?? 5.0),
                'total_reviews' => $providerProfile->total_reviews ?? 0,
                'radius_km' => 15,
                'specialties' => ['Cerrajería residencial', 'Cerrajería automotor', 'Urgencias 24h'],
                'schedules' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
                'availability_status' => $providerProfile->availability_status instanceof \BackedEnum
                    ? $providerProfile->availability_status->value
                    : $providerProfile->availability_status,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'bio' => 'nullable|string|max:300',
            'phone' => 'nullable|string|max:30',
            'radius_km' => 'nullable|integer|min:1|max:100',
            'specialties' => 'nullable|array',
            'schedules' => 'nullable|array',
        ]);

        if ($request->has('phone')) {
            $user->update(['phone' => $request->input('phone')]);
        }

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if ($providerProfile) {
            if ($request->has('bio')) {
                $providerProfile->update(['bio' => $request->input('bio')]);
            }
        }

        return response()->json([
            'message' => 'Perfil profesional actualizado.',
            'data' => [
                'bio' => $request->input('bio'),
                'phone' => $user->phone,
                'radius_km' => $request->input('radius_km', 15),
                'specialties' => $request->input('specialties', []),
                'schedules' => $request->input('schedules', []),
            ],
        ]);
    }
}
