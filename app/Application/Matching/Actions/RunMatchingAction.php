<?php

namespace App\Application\Matching\Actions;

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use Illuminate\Support\Collection;

final class RunMatchingAction
{
    private const WEIGHTS = [
        'reputation'    => 0.30,
        'distance'      => 0.25,
        'availability'  => 0.20,
        'experience'    => 0.15,
        'response_rate' => 0.10,
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

        $requestDateStr = null;
        if ($urgencyVal === 'today') {
            $requestDateStr = \Carbon\Carbon::now('America/Argentina/Buenos_Aires')->format('Y-m-d');
        } elseif ($request->scheduled_date) {
            $requestDateStr = $request->scheduled_date instanceof \Carbon\Carbon
                ? $request->scheduled_date->format('Y-m-d')
                : (string) $request->scheduled_date;
            if (strlen($requestDateStr) > 10) {
                $requestDateStr = substr($requestDateStr, 0, 10);
            }
        } elseif ($request->preferred_datetime) {
            $requestDateStr = $request->preferred_datetime->copy()->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d');
        }

        $requestStartArg = null;
        $requestEndArg = null;

        if ($urgencyVal === 'immediate') {
            $requestStartArg = \Carbon\Carbon::now('America/Argentina/Buenos_Aires');
            $requestEndArg = $requestStartArg->copy()->addMinutes(60);
        } elseif ($requestDateStr) {
            if ($request->window_start && $request->window_end) {
                $startStr = trim($request->window_start);
                $endStr = trim($request->window_end);
                if (strlen($startStr) === 5) $startStr .= ':00';
                if (strlen($endStr) === 5) $endStr .= ':00';

                // FIX: Parse ALWAYS in Argentina timezone for consistency
                $requestStartArg = \Carbon\Carbon::parse("{$requestDateStr} {$startStr}", 'America/Argentina/Buenos_Aires');
                $requestEndArg = \Carbon\Carbon::parse("{$requestDateStr} {$endStr}", 'America/Argentina/Buenos_Aires');
            } elseif ($request->preferred_datetime && $request->preferred_datetime->format('H:i:s') !== '00:00:00') {
                $dtStr = $request->preferred_datetime->format('Y-m-d H:i:s');
                $requestStartArg = \Carbon\Carbon::parse($dtStr, 'America/Argentina/Buenos_Aires');
                $requestEndArg = $requestStartArg->copy()->addMinutes(60);
            } else {
                $requestStartArg = \Carbon\Carbon::parse("{$requestDateStr} 00:00:00", 'America/Argentina/Buenos_Aires');
                $requestEndArg = \Carbon\Carbon::parse("{$requestDateStr} 23:59:59", 'America/Argentina/Buenos_Aires');
            }
        }

        // FIX: Convert to UTC strings for database comparison
        $requestStartUtcStr = $requestStartArg?->utc()->toIso8601String();
        $requestEndUtcStr = $requestEndArg?->utc()->toIso8601String();

        $query = ProviderProfileModel::query()
            ->whereHas('mvu', function ($q) {
                $q->where('overall_verification_status', 'approved');
            })
            ->where('availability_status', '!=', 'unavailable')
            ->whereHas('categories', function ($q) use ($request) {
                $q->where('category_id', $request->category_id)
                  ->where('is_active', true);
            })
            ->when(!$request->is_remote && $request->location_lat, function ($q) use ($request) {
                $q->where(function ($query) use ($request) {
                    $query->whereHas('serviceAreas', function ($sq) use ($request) {
                        $sq->whereRaw("
                            (6371 * acos(
                                LEAST(1.0, GREATEST(-1.0,
                                    cos(radians(?)) * cos(radians(center_lat)) *
                                    cos(radians(center_lng) - radians(?)) +
                                    sin(radians(?)) * sin(radians(center_lat))
                                ))
                            )) <= radius_km
                        ", [
                            $request->location_lat,
                            $request->location_lng,
                            $request->location_lat,
                        ]);
                    })
                    ->orWhere(function ($sq) use ($request) {
                        $sq->doesntHave('serviceAreas')
                          ->whereRaw("
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
                    });
                });
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
            ->when($requestStartUtcStr && $requestEndUtcStr, function ($q) use ($requestStartUtcStr, $requestEndUtcStr) {
                $q->whereDoesntHave('works', function ($sq) use ($requestStartUtcStr, $requestEndUtcStr) {
                    $sq->whereIn('status', ['confirmed', 'in_progress'])
                      ->whereNotNull('scheduled_at')
                      ->whereRaw("
                        scheduled_at < ?
                        AND (
                            COALESCE(scheduled_ends_at, scheduled_at + (COALESCE(estimated_duration_min, 60) || ' minutes')::interval)
                            > ?
                        )
                    ", [$requestEndUtcStr, $requestStartUtcStr]);
                });
            })
            ->with(['categories' => function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            }, 'user', 'serviceAreas']);

        return $query->get();
    }

    private function calculateBreakdown($provider, $request): array
    {
        return [
            'reputation'    => $this->scoreReputation($provider),
            'distance'      => $this->scoreDistance($provider, $request),
            'availability'  => $this->scoreAvailability($provider, $request),
            'experience'    => $this->scoreExperience($provider, $request),
            'response_rate' => round(($provider->response_rate ?? 100) / 100, 4),
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
        
        $distanceKm = $this->getEffectiveDistanceKm($provider, $request);
        if ($distanceKm === null) return 0.5;

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
        $distanceKm = $this->getEffectiveDistanceKm($provider, $request);

        $etaMinutes = $distanceKm !== null
            ? (int) ceil(($distanceKm / 30) * 60) + 5
            : null;

        $categoryData = $provider->categories->first();
        $status = $provider->availability_status instanceof \BackedEnum
            ? $provider->availability_status->value
            : (string) $provider->availability_status;

        return [
            'distance_km'         => $distanceKm !== null ? round($distanceKm, 1) : null,
            'eta_minutes'         => $etaMinutes,
            'availability_status' => $status,
            'next_available_at'   => $provider->next_available_at?->toISOString(),
            'avg_rating'          => (float) $provider->avg_rating,
            'total_reviews'       => (int) $provider->total_reviews,
            'price_from'          => $categoryData?->price_from ? (float)$categoryData->price_from : null,
        ];
    }

    private function getEffectiveDistanceKm($provider, $request): ?float
    {
        if (!$request->location_lat || !$request->location_lng) return null;

        $minDistance = null;
        if ($provider->base_lat && $provider->base_lng) {
            $minDistance = $this->calculateDistanceKm(
                (float)$provider->base_lat, (float)$provider->base_lng,
                (float)$request->location_lat, (float)$request->location_lng
            );
        }

        if ($provider->relationLoaded('serviceAreas') || $provider->serviceAreas) {
            foreach ($provider->serviceAreas as $sa) {
                if ($sa->center_lat && $sa->center_lng) {
                    $d = $this->calculateDistanceKm(
                        (float)$sa->center_lat, (float)$sa->center_lng,
                        (float)$request->location_lat, (float)$request->location_lng
                    );
                    if ($minDistance === null || $d < $minDistance) {
                        $minDistance = $d;
                    }
                }
            }
        }

        return $minDistance;
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
