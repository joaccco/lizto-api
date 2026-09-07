<?php

namespace App\Domain\Location\Services;

use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;

class LocationPresenter
{
    private const ZONE_CENTROIDS = [
        'palermo' => ['lat' => -34.5889, 'lng' => -58.4305, 'name' => 'Palermo'],
        'recoleta' => ['lat' => -34.5875, 'lng' => -58.3974, 'name' => 'Recoleta'],
        'belgrano' => ['lat' => -34.5627, 'lng' => -58.4564, 'name' => 'Belgrano'],
        'centro' => ['lat' => -27.4692, 'lng' => -58.8306, 'name' => 'Zona Centro'],
        'corrientes' => ['lat' => -27.4692, 'lng' => -58.8306, 'name' => 'Corrientes'],
    ];

    public static function canViewExact(ServiceRequestModel $request, ?UserModel $user): bool
    {
        if (!$user) {
            return false;
        }

        if ((int) $request->client_id === (int) $user->id) {
            return true;
        }

        $providerProfile = $user->providerProfile;
        if ($providerProfile) {
            $hasConfirmedWork = $request->works()
                ->where('provider_id', $providerProfile->id)
                ->where(function ($q) {
                    $q->whereNotNull('confirmed_at')
                      ->orWhereIn('status', [
                          WorkStatus::Confirmed->value,
                          WorkStatus::InProgress->value,
                          WorkStatus::PendingCompletion->value,
                          WorkStatus::Completed->value,
                      ]);
                })
                ->exists();

            if ($hasConfirmedWork) {
                return true;
            }
        }

        return false;
    }

    public static function present(ServiceRequestModel $request, ?UserModel $user): array
    {
        $canViewExact = self::canViewExact($request, $user);

        if ($canViewExact && $request->location_lat !== null && $request->location_lng !== null) {
            return [
                'location_address' => $request->location_address ?? 'Centro',
                'address' => $request->location_address ?? 'Centro',
                'location_lat' => (float) $request->location_lat,
                'location_lng' => (float) $request->location_lng,
                'is_approximate' => false,
            ];
        }

        if ($canViewExact) {
            \Illuminate\Support\Facades\Log::warning('Confirmed work has missing coordinates, falling back to approximate zone', [
                'request_id' => $request->id,
            ]);
        }

        $zoneInfo = self::getApproximateZone($request->location_address, $request->location_lat, $request->location_lng);

        return [
            'location_address' => $zoneInfo['name'],
            'address' => $zoneInfo['name'],
            'location_lat' => (float) $zoneInfo['lat'],
            'location_lng' => (float) $zoneInfo['lng'],
            'location_radius_meters' => 500,
            'is_approximate' => true,
        ];
    }

    public static function getApproximateZone(?string $rawAddress, $lat, $lng): array
    {
        $addressLower = mb_strtolower($rawAddress ?? '');

        foreach (self::ZONE_CENTROIDS as $key => $info) {
            if (str_contains($addressLower, $key)) {
                return $info;
            }
        }

        if ($lat === null || $lng === null) {
            \Illuminate\Support\Facades\Log::warning('Location data is incomplete and cannot be approximated, using fallback');
            $roundedLat = -27.47;
            $roundedLng = -58.83;
        } else {
            $roundedLat = round((float) $lat, 2);
            $roundedLng = round((float) $lng, 2);
        }

        $zoneName = 'Zona Centro';
        if (!empty($rawAddress)) {
            $parts = explode(',', $rawAddress);
            $zoneCandidate = trim(end($parts));
            if (!empty($zoneCandidate) && strlen($zoneCandidate) > 3 && !preg_match('/\d/', $zoneCandidate)) {
                $zoneName = $zoneCandidate;
            }
        }

        return [
            'name' => $zoneName,
            'lat' => $roundedLat,
            'lng' => $roundedLng,
        ];
    }

    public static function getZoneFromCoordinates(?float $lat, ?float $lng): string
    {
        $zone = self::getApproximateZone(null, $lat, $lng);
        return $zone['name'] ?? 'Zona Urbana';
    }

    public static function getZoneCenter(string $zoneName): array
    {
        $nameLower = mb_strtolower($zoneName);
        foreach (self::ZONE_CENTROIDS as $key => $info) {
            if (str_contains($nameLower, $key)) {
                return ['latitude' => $info['lat'], 'longitude' => $info['lng']];
            }
        }
        return ['latitude' => -34.6037, 'longitude' => -58.3816];
    }

    public static function calculateDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusKm * $c, 2);
    }

    public static function calculateETA(float $distanceKm, ?float $speedKmh = null): int
    {
        $effectiveSpeed = ($speedKmh && $speedKmh > 5 && $speedKmh <= 150) ? $speedKmh : 30.0;
        $minutes = ($distanceKm / $effectiveSpeed) * 60;
        return max(1, (int) round($minutes));
    }
}

