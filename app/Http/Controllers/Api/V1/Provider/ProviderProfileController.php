<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderProfileController extends Controller
{
    private const DAY_CODE_MAP = [
        1 => 'mon',
        2 => 'tue',
        3 => 'wed',
        4 => 'thu',
        5 => 'fri',
        6 => 'sat',
        7 => 'sun',
    ];

    private const DAY_NUM_MAP = [
        'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
        1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7,
    ];

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

        $area = $providerProfile->serviceAreas->first();
        $radiusKm = $area ? (int) $area->radius_km : 15;

        $category = $providerProfile->categories->first();
        $specialties = $category ? ($category->specialties ?? []) : [];

        $schedules = $providerProfile->schedules->map(function ($s) {
            return self::DAY_CODE_MAP[$s->day_of_week] ?? $s->day_of_week;
        })->values()->toArray();

        if (empty($schedules)) {
            $schedules = ['mon', 'tue', 'wed', 'thu', 'fri'];
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
                'radius_km' => $radiusKm,
                'specialties' => $specialties,
                'schedules' => $schedules,
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

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if (!$providerProfile) {
            $providerProfile = ProviderProfileModel::create([
                'user_id' => $user->id,
                'bio' => $request->input('bio', ''),
                'availability_status' => 'available',
            ]);
        }

        DB::transaction(function () use ($user, $providerProfile, $request) {
            if ($request->has('phone')) {
                $user->update(['phone' => $request->input('phone')]);
            }

            if ($request->has('bio')) {
                $providerProfile->update(['bio' => $request->input('bio')]);
            }

            if ($request->has('radius_km')) {
                $radius = $request->input('radius_km');
                $area = $providerProfile->serviceAreas()->first();
                if ($area) {
                    $area->update(['radius_km' => $radius]);
                } else {
                    $providerProfile->serviceAreas()->create([
                        'center_lat' => $providerProfile->base_lat ?? -27.4692,
                        'center_lng' => $providerProfile->base_lng ?? -58.8306,
                        'radius_km' => $radius,
                        'label' => 'Principal',
                    ]);
                }
            }

            if ($request->has('specialties')) {
                $specialties = $request->input('specialties', []);
                $cat = $providerProfile->categories()->first();
                if ($cat) {
                    $cat->update(['specialties' => $specialties]);
                } else {
                    $category = CategoryModel::first();
                    if ($category) {
                        $providerProfile->categories()->create([
                            'category_id' => $category->id,
                            'specialties' => $specialties,
                        ]);
                    }
                }
            }

            if ($request->has('schedules')) {
                $schedulesInput = $request->input('schedules', []);
                $providerProfile->schedules()->delete();

                foreach ($schedulesInput as $day) {
                    $dayNum = self::DAY_NUM_MAP[$day] ?? null;
                    if ($dayNum) {
                        $providerProfile->schedules()->create([
                            'day_of_week' => $dayNum,
                            'start_time' => '08:00:00',
                            'end_time' => '18:00:00',
                            'is_active' => true,
                        ]);
                    }
                }
            }
        });

        // Read REAL state from database
        $providerProfile->load(['user', 'categories', 'serviceAreas', 'schedules']);
        $area = $providerProfile->serviceAreas->first();
        $radiusKm = $area ? (int) $area->radius_km : 15;

        $cat = $providerProfile->categories->first();
        $specialties = $cat ? ($cat->specialties ?? []) : [];

        $schedules = $providerProfile->schedules->map(function ($s) {
            return self::DAY_CODE_MAP[$s->day_of_week] ?? $s->day_of_week;
        })->values()->toArray();

        return response()->json([
            'message' => 'Perfil profesional actualizado.',
            'data' => [
                'bio' => $providerProfile->bio ?? '',
                'phone' => $user->phone ?? '',
                'radius_km' => $radiusKm,
                'specialties' => $specialties,
                'schedules' => $schedules,
            ],
        ]);
    }
}
