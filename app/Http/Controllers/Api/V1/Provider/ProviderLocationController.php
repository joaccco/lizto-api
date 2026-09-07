<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Location\Events\ProviderLocationUpdated;
use App\Domain\Location\Services\LocationPresenter;
use App\Domain\Works\Enums\WorkStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ProviderLocationModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Traits\ResolvesByUuid;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ProviderLocationController extends Controller
{
    use ResolvesByUuid;

    /**
     * Provider emits current GPS coordinates.
     * Throttled to max 1 update every 5 seconds.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if (!$providerProfile) {
            return response()->json(['message' => 'Perfil de proveedor no encontrado.'], 404);
        }

        // Anti-spam throttling: 1 update every 5 seconds per provider
        $throttleKey = "provider-location:{$providerProfile->id}";
        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => 'Demasiadas actualizaciones. Espera unos segundos.',
                'retry_after_seconds' => $seconds,
            ], 429)->header('Retry-After', (string) $seconds);
        }
        RateLimiter::hit($throttleKey, 5);

        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy_meters' => 'nullable|integer|between:0,10000',
            'heading' => 'nullable|integer|between:0,359',
            'speed_kmh' => 'nullable|numeric|between:0,200',
        ]);

        $location = ProviderLocationModel::create([
            'provider_id' => $providerProfile->id,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'accuracy_meters' => $validated['accuracy_meters'] ?? 10,
            'heading' => $validated['heading'] ?? null,
            'speed_kmh' => $validated['speed_kmh'] ?? null,
        ]);

        // Find active in_progress work if any to attach to event
        $activeWork = WorkModel::where('provider_id', $providerProfile->id)
            ->whereIn('status', [WorkStatus::Confirmed->value, WorkStatus::InProgress->value])
            ->latest('updated_at')
            ->first();

        event(new ProviderLocationUpdated($location, $activeWork?->id));

        return response()->json([
            'provider_id' => $providerProfile->id,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'approximate_zone' => $location->approximate_zone,
            'heading' => $location->heading,
            'speed_kmh' => $location->speed_kmh,
            'created_at' => $location->created_at?->toISOString(),
        ], 200);
    }

    /**
     * Client tracks assigned provider's real-time location.
     * Strictly enforces D-01 Geo-Privacy: returns approximate zone and ETA only.
     */
    public function show(string $workId, Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $work = $this->findByUuid(WorkModel::class, $workId);
        if (!$work) {
            return response()->json(['message' => 'Trabajo no encontrado.'], 404);
        }

        // Preconditions: Client must own the work (or be the assigned provider/admin)
        $isClientOwner = (int) $work->client_id === (int) $user->id;
        $isAssignedProvider = $user->providerProfile && (int) $work->provider_id === (int) $user->providerProfile->id;
        $isAdmin = $user->hasRole('admin');

        if (!$isClientOwner && !$isAssignedProvider && !$isAdmin) {
            return response()->json(['message' => 'No autorizado para ver la ubicación del proveedor para este trabajo.'], 403);
        }

        // Work must be active (confirmed or in_progress)
        $statusVal = $work->status instanceof \BackedEnum ? $work->status->value : (string) $work->status;
        $activeStatuses = [
            WorkStatus::Confirmed->value,
            WorkStatus::InProgress->value,
            WorkStatus::PendingDiagnosisQuote->value,
        ];

        if (!in_array($statusVal, $activeStatuses, true)) {
            return response()->json(['message' => 'El seguimiento solo está disponible para trabajos en curso o confirmados.'], 404);
        }

        // Fetch latest location for the assigned provider
        $latestLocation = ProviderLocationModel::where('provider_id', $work->provider_id)
            ->orderByDesc('created_at')
            ->first();

        if (!$latestLocation) {
            return response()->json(['message' => 'No hay datos de ubicación disponibles para este proveedor.'], 404);
        }

        // Calculate distance and ETA to work destination
        $workLat = $work->work_lat ?? $work->serviceRequest?->location_lat;
        $workLng = $work->work_lng ?? $work->serviceRequest?->location_lng;

        $etaMinutes = 10;
        if ($workLat !== null && $workLng !== null) {
            $distanceKm = LocationPresenter::calculateDistanceKm(
                (float) $latestLocation->latitude,
                (float) $latestLocation->longitude,
                (float) $workLat,
                (float) $workLng
            );
            $etaMinutes = LocationPresenter::calculateETA($distanceKm, $latestLocation->speed_kmh);
        }

        $approximateZone = $latestLocation->approximate_zone;
        $zoneCenter = LocationPresenter::getZoneCenter($approximateZone);

        // D-01 Geo-Privacy: Client sees approximate zone and center, NEVER raw coordinates
        $data = [
            'provider_id' => $work->provider_id,
            'approximate_zone' => $approximateZone,
            'approximate_center' => $zoneCenter,
            'heading' => $latestLocation->heading,
            'speed_kmh' => $latestLocation->speed_kmh,
            'estimated_arrival_minutes' => $etaMinutes,
            'last_update' => $latestLocation->created_at?->toISOString(),
            'is_approximate' => true,
        ];

        // Only provider self or admin can inspect raw coordinates
        if ($isAssignedProvider || $isAdmin) {
            $data['raw_latitude'] = $latestLocation->latitude;
            $data['raw_longitude'] = $latestLocation->longitude;
        }

        return response()->json([
            'data' => $data,
        ], 200);
    }
}
