<?php

namespace App\Http\Controllers\Api\V1\Providers;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Http\Controllers\Controller;
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
            ->with(['user', 'categories.category', 'serviceAreas', 'portfolioItems', 'reviews.reviewer']);

        if ($request->filled('category')) {
            $categorySlug = $request->input('category');
            $query->whereHas('categories.category', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        }

        $query->orderByDesc('avg_rating');
        $providers = $query->paginate(15);

        $items = collect($providers->items())->map(fn($p) => $this->formatPublicProfile($p));

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $providers->currentPage(),
                'per_page' => $providers->perPage(),
                'total' => $providers->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
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

        return response()->json([
            'data' => $this->formatPublicProfile($provider),
        ]);
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

    private function formatPublicProfile(ProviderProfileModel $provider): array
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

        return [
            'id' => $provider->uuid ?? $provider->user?->uuid ?? (string) $provider->id,
            'uuid' => $provider->user?->uuid ?? $provider->uuid ?? (string) $provider->id,
            'name' => $provider->commercial_name ?: ($provider->user?->name ?? 'Profesional'),
            'commercial_name' => $provider->commercial_name,
            'user_name' => $provider->user?->name,
            'email' => $provider->user?->email,
            'avatar_url' => $provider->user?->avatar_url,
            'bio' => $provider->bio ?? 'Profesional verificado en Lizto.',
            'category_name' => $category?->category?->name ?? 'Servicio general',
            'specialties' => $specialties,
            'years_experience' => (int) ($provider->years_experience ?? 3),
            'avg_rating' => (float) ($provider->avg_rating ?? 5.0),
            'total_reviews' => (int) ($provider->total_reviews ?? 0),
            'total_jobs_completed' => (int) ($provider->total_jobs_completed ?? 0),
            'is_verified' => (bool) ($provider->is_verified ?? true),
            'status' => $provider->status instanceof \BackedEnum ? $provider->status->value : $provider->status,
            'availability_status' => $availStatus,
            'availability' => [
                'status' => $availStatus,
                'busy_until' => $provider->busy_until?->toISOString(),
            ],
            'location' => [
                'address' => $provider->base_address ?? 'Centro',
                'lat' => $provider->base_lat ? (float) $provider->base_lat : -27.4692,
                'lng' => $provider->base_lng ? (float) $provider->base_lng : -58.8306,
            ],
            'distance_km' => 1.5,
            'base_address' => $provider->base_address ?? 'Centro',
            'radius_km' => $area ? (int) $area->radius_km : 15,
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
