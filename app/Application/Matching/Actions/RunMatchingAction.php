<?php

namespace App\Application\Matching\Actions;

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use Illuminate\Support\Collection;

final class RunMatchingAction
{
    private const WEIGHTS = [
        'reputation'    => 0.25,
        'distance'      => 0.25,
        'availability'  => 0.20,
        'experience'    => 0.15,
        'response_rate' => 0.10,
        'verified'      => 0.05,
    ];

    private const RANDOM_BAND   = 0.03;
    private const MAX_DISTANCE  = 25;
    private const MAX_RESULTS   = 10;

    public function execute(ServiceRequestModel $request): array
    {
        $candidates = $this->applyHardFilters($request);

        if ($candidates->isEmpty()) {
            return [];
        }

        $scored = $candidates->map(function ($provider) use ($request) {
            $breakdown  = $this->calculateBreakdown($provider, $request);
            $total      = $this->calculateTotal($breakdown);
            $jitter     = (mt_rand(-100, 100) / 100) * self::RANDOM_BAND;
            $finalScore = round(min(1.0, max(0.0, $total + $jitter)), 4);

            return [
                'provider'        => $provider,
                'score_total'     => $finalScore,
                'score_breakdown' => $breakdown,
                'snapshot'        => $this->buildSnapshot($provider, $request),
            ];
        });

        return $scored
            ->sortByDesc('score_total')
            ->take(self::MAX_RESULTS)
            ->values()
            ->toArray();
    }

    private function applyHardFilters(ServiceRequestModel $request): Collection
    {
        $urgencyVal = $request->urgency instanceof \BackedEnum ? $request->urgency->value : $request->urgency;

        return ProviderProfileModel::query()
            ->where('availability_status', '!=', 'unavailable')
            ->whereHas('categories', function ($q) use ($request) {
                $q->where('category_id', $request->category_id)
                  ->where('is_active', true);
            })
            ->when(!$request->is_remote && $request->location_lat, function ($q) use ($request) {
                $q->whereRaw("
                    (6371 * acos(
                        LEAST(1.0, GREATEST(-1.0,
                            cos(radians(?)) * cos(radians(base_lat)) *
                            cos(radians(base_lng) - radians(?)) +
                            sin(radians(?)) * sin(radians(base_lat))
                        ))
                    )) <= ?
                ", [
                    $request->location_lat,
                    $request->location_lng,
                    $request->location_lat,
                    self::MAX_DISTANCE,
                ]);
            })
            ->when($urgencyVal === 'immediate', function ($q) {
                $q->where(function ($q) {
                    $q->where('availability_status', 'available')
                      ->orWhere(function ($q) {
                          $q->where('availability_status', 'busy')
                            ->where('next_available_at', '<=', now()->addMinutes(60));
                      });
                });
            })
            ->with(['categories' => function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            }, 'user'])
            ->get();
    }

    private function calculateBreakdown($provider, $request): array
    {
        return [
            'reputation'    => $this->scoreReputation($provider),
            'distance'      => $this->scoreDistance($provider, $request),
            'availability'  => $this->scoreAvailability($provider, $request),
            'experience'    => $this->scoreExperience($provider, $request),
            'response_rate' => round(($provider->response_rate ?? 100) / 100, 4),
            'verified'      => $provider->is_verified ? 1.0 : 0.0,
        ];
    }

    private function calculateTotal(array $breakdown): float
    {
        $total = 0;
        foreach (self::WEIGHTS as $key => $weight) {
            $total += ($breakdown[$key] ?? 0) * $weight;
        }
        return round($total, 4);
    }

    private function scoreReputation($provider): float
    {
        if ($provider->total_reviews === 0) return 0.5;

        $ratingScore         = ($provider->avg_rating - 1) / 4;
        $reviewWeight        = min(1.0, $provider->total_reviews / 50);
        $completionScore     = ($provider->completion_rate ?? 100) / 100;
        $cancellationPenalty = min(0.3, ($provider->cancellation_count ?? 0) * 0.05);

        return round(
            ($ratingScore * 0.5 + $reviewWeight * 0.3 + $completionScore * 0.2)
            - $cancellationPenalty,
            4
        );
    }

    private function scoreDistance($provider, $request): float
    {
        if ($request->is_remote) return 1.0;
        if (!$request->location_lat || !$provider->base_lat) return 0.5;

        $distanceKm = $this->calculateDistanceKm(
            (float)$provider->base_lat, (float)$provider->base_lng,
            (float)$request->location_lat, (float)$request->location_lng
        );

        return round(max(0, 1 - ($distanceKm / self::MAX_DISTANCE)), 4);
    }

    private function scoreAvailability($provider, $request): float
    {
        $status = $provider->availability_status instanceof \BackedEnum
            ? $provider->availability_status->value
            : (string) $provider->availability_status;

        return match($status) {
            'available' => 1.0,
            'busy'      => $this->scoreBusyProvider($provider, $request),
            default     => 0.0,
        };
    }

    private function scoreBusyProvider($provider, $request): float
    {
        if (!$provider->next_available_at) return 0.1;

        $minutes = now()->diffInMinutes($provider->next_available_at);
        $urgencyVal = $request->urgency instanceof \BackedEnum ? $request->urgency->value : $request->urgency;

        if ($urgencyVal === 'immediate') {
            return match(true) {
                $minutes <= 30  => 0.7,
                $minutes <= 60  => 0.4,
                default         => 0.1,
            };
        }

        return match(true) {
            $minutes <= 120 => 0.8,
            $minutes <= 360 => 0.6,
            default         => 0.3,
        };
    }

    private function scoreExperience($provider, $request): float
    {
        $categoryJobs = $provider->total_jobs_completed ?? 0;
        return round(min(1.0, $categoryJobs / 30), 4);
    }

    private function buildSnapshot($provider, $request): array
    {
        $distanceKm = ($request->location_lat && $provider->base_lat)
            ? $this->calculateDistanceKm(
                (float)$provider->base_lat, (float)$provider->base_lng,
                (float)$request->location_lat, (float)$request->location_lng
              )
            : null;

        $etaMinutes = $distanceKm
            ? (int) ceil(($distanceKm / 30) * 60) + 5
            : null;

        $categoryData = $provider->categories->first();
        $status = $provider->availability_status instanceof \BackedEnum
            ? $provider->availability_status->value
            : (string) $provider->availability_status;

        return [
            'distance_km'         => $distanceKm ? round($distanceKm, 1) : null,
            'eta_minutes'         => $etaMinutes,
            'availability_status' => $status,
            'next_available_at'   => $provider->next_available_at?->toISOString(),
            'avg_rating'          => (float) $provider->avg_rating,
            'total_reviews'       => (int) $provider->total_reviews,
            'price_from'          => $categoryData?->price_from ? (float)$categoryData->price_from : null,
        ];
    }

    private function calculateDistanceKm(
        float $lat1, float $lng1,
        float $lat2, float $lng2
    ): float {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
           * sin($dLng/2) ** 2;
        return round($earthRadius * 2 * atan2(sqrt($a), sqrt(1-$a)), 2);
    }
}
