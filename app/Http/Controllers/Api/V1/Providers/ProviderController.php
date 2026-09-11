<?php

namespace App\Http\Controllers\Api\V1\Providers;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderDetailResource;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProviderProfileModel::query()
            ->eligibleForMatching()
            ->with(['user', 'categories.category', 'serviceAreas', 'portfolioItems', 'reviews.reviewer', 'mvu']);

        if ($request->filled('category')) {
            $categorySlug = $request->input('category');
            $query->whereHas('categories.category', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        }

        $query->orderByDesc('avg_rating');
        $providers = $query->paginate(15);

        $items = collect($providers->items())->map(fn($p) => $this->formatPublicProfile($p, $request));

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $providers->currentPage(),
                'per_page' => $providers->perPage(),
                'total' => $providers->total(),
            ],
        ]);
    }

    public function show(string $uuid, Request $request): ProviderDetailResource
    {
        $user = UserModel::where('uuid', $uuid)->first();
        $provider = null;

        if ($user) {
            $provider = ProviderProfileModel::where('user_id', $user->id)
                ->with(['user', 'categories.category', 'serviceAreas', 'schedules', 'portfolioItems', 'reviews.reviewer'])
                ->first();
        }

        if (!$provider) {
            $query = ProviderProfileModel::query();
            if (is_numeric($uuid)) {
                $query->where('id', (int) $uuid);
            } else {
                $query->where('uuid', $uuid);
            }
            $provider = $query->with(['user', 'categories.category', 'serviceAreas', 'schedules', 'portfolioItems', 'reviews.reviewer'])->firstOrFail();
        }

        return new ProviderDetailResource($provider);
    }

    public function reviews(string $uuid, Request $request): JsonResponse
    {
        $user = UserModel::where('uuid', $uuid)->first();
        $provider = null;

        if ($user) {
            $provider = ProviderProfileModel::where('user_id', $user->id)->first();
        }

        if (!$provider) {
            $query = ProviderProfileModel::query();
            if (is_numeric($uuid)) {
                $query->where('id', (int) $uuid);
            } else {
                $query->where('uuid', $uuid);
            }
            $provider = $query->firstOrFail();
        }

        $reviews = $provider->reviews()->with('reviewer')->paginate(10);

        $data = collect($reviews->items())->map(function ($r) {
            $parts = explode(' ', $r->reviewer?->name ?? 'Cliente Lizto');
            $shortName = count($parts) > 1
                ? $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.'
                : $parts[0];

            return [
                'id' => $r->id,
                'score' => (int) $r->score,
                'comment' => $r->comment,
                'reviewer_name' => $shortName,
                'created_at' => $r->created_at ? (is_string($r->created_at) ? $r->created_at : $r->created_at->toISOString()) : now()->toISOString(),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    private function formatPublicProfile(ProviderProfileModel $provider, ?Request $request = null): array
    {
        $category = $provider->categories->first();
        $specialties = $category ? ($category->specialties ?? []) : [];
        $area = $provider->serviceAreas->first();

        $badges = ["Profesional Verificado"];
        if (($provider->avg_rating ?? 5.0) >= 4.8) {
            $badges[] = "Top Profesional";
        }
        if (($provider->avg_response_minutes ?? 60) <= 15) {
            $badges[] = "Responde Rápido";
        }
        if (($provider->total_jobs_completed ?? 0) >= 10) {
            $badges[] = "{$provider->total_jobs_completed}+ trabajos completados";
        }

        $availStatus = $provider->availability_status instanceof \BackedEnum
            ? $provider->availability_status->value
            : ($provider->availability_status ?? 'available');

        $formattedReviews = $provider->reviews->take(10)->map(function ($r) {
            $parts = explode(' ', $r->reviewer?->name ?? 'Cliente Lizto');
            $shortName = count($parts) > 1
                ? $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.'
                : $parts[0];

            return [
                'id' => $r->id,
                'score' => (int) $r->score,
                'comment' => $r->comment,
                'reviewer_name' => $shortName,
                'created_at' => $r->created_at ? (is_string($r->created_at) ? $r->created_at : $r->created_at->toISOString()) : now()->toISOString(),
            ];
        })->values()->toArray();

        $distanceKm = null;
        if ($request) {
            $clientLat = $request->input('lat') ?? $request->input('client_lat');
            $clientLng = $request->input('lng') ?? $request->input('client_lng');
            if ($clientLat !== null && $clientLng !== null && $provider->base_lat !== null && $provider->base_lng !== null) {
                $distanceKm = \App\Domain\Location\Services\LocationPresenter::calculateDistanceKm(
                    (float) $clientLat,
                    (float) $clientLng,
                    (float) $provider->base_lat,
                    (float) $provider->base_lng
                );
            }
        }

        $zoneName = null;
        if ($provider->base_address || ($provider->base_lat !== null && $provider->base_lng !== null)) {
            $zone = \App\Domain\Location\Services\LocationPresenter::getApproximateZone(
                $provider->base_address,
                $provider->base_lat,
                $provider->base_lng
            );
            $zoneName = $zone['name'] ?? null;
        }

        return [
            'id' => $provider->uuid ?? $provider->user?->uuid ?? (string) $provider->id,
            'uuid' => $provider->user?->uuid ?? $provider->uuid ?? (string) $provider->id,
            'name' => $provider->commercial_name ?: ($provider->user?->name ?? 'Profesional'),
            'commercial_name' => $provider->commercial_name,
            'user_name' => $provider->user?->name,
            'avatar_url' => $provider->user?->avatar_url,
            'bio' => $provider->bio,
            'category_name' => $category?->category?->name,
            'specialties' => $specialties,
            'years_experience' => $provider->years_experience !== null ? (int) $provider->years_experience : null,
            'avg_rating' => (float) ($provider->avg_rating ?? 5.0),
            'total_reviews' => (int) ($provider->total_reviews ?? 0),
            'total_jobs_completed' => (int) ($provider->total_jobs_completed ?? 0),
            'is_verified' => (bool) ($provider->mvu?->overall_verification_status === 'approved'),
            'status' => $provider->status instanceof \BackedEnum ? $provider->status->value : $provider->status,
            'availability_status' => $availStatus,
            'availability' => [
                'status' => $availStatus,
                'busy_until' => $provider->busy_until?->toISOString(),
            ],
            'location' => [
                'address' => $zoneName,
                'zone' => $zoneName,
                'distance_km' => $distanceKm,
            ],
            'distance_km' => $distanceKm,
            'base_address' => $zoneName,
            'radius_km' => $area ? (int) $area->radius_km : null,
            'badges' => $badges,
            'reputation_stats' => [
                'avg_rating' => (float) ($provider->avg_rating ?? 5.0),
                'total_reviews' => (int) ($provider->total_reviews ?? 0),
                'completion_rate' => (float) ($provider->completion_rate ?? 100),
            ],
            'categories' => $provider->categories->map(fn($c) => [
                'id' => $c->category_id,
                'name' => $c->category?->name ?? 'Cerrajería',
                'slug' => $c->category?->slug ?? 'cerrajeria',
                'specialties' => $c->specialties ?? [],
            ]),
            'service_areas' => $provider->serviceAreas->map(fn($a) => [
                'radius_km' => $a->radius_km,
                'label' => $a->label,
            ]),
            'schedules' => $provider->schedules->map(fn($s) => [
                'day_of_week' => $s->day_of_week,
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
            ]),
            'portfolio' => $provider->portfolioItems->map(fn($item) => [
                'id' => $item->uuid ?? (string) $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'media_urls' => $item->media_urls ?? [],
            ]),
            'reviews' => $formattedReviews,
        ];
    }
}
