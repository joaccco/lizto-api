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
                ->whereIn('status', [
                    WorkStatus::Confirmed->value,
                    WorkStatus::InProgress->value,
                    WorkStatus::PendingCompletion->value,
                    WorkStatus::Completed->value,
                ])
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

        if ($canViewExact) {
            return [
                'location_address' => $request->location_address ?? 'Centro',
                'address' => $request->location_address ?? 'Centro',
                'location_lat' => $request->location_lat ? (float) $request->location_lat : -27.4692,
                'location_lng' => $request->location_lng ? (float) $request->location_lng : -58.8306,
                'is_approximate' => false,
            ];
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

        $roundedLat = $lat !== null ? round((float) $lat, 2) : -27.47;
        $roundedLng = $lng !== null ? round((float) $lng, 2) : -58.83;

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
}
