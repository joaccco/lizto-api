<?php

namespace App\Http\Resources;

use App\Domain\Providers\Enums\AvailabilityStatus;
use App\Infrastructure\Persistence\Eloquent\RatingModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;

        // Categories with details & specialties
        $categoriesData = $this->relationLoaded('categories') ? $this->categories->map(function ($providerCategory) {
            $cat = $providerCategory->category;
            return [
                'id'          => $cat?->slug ?? (string) $providerCategory->category_id,
                'name'        => $cat?->name,
                'slug'        => $cat?->slug,
                'icon'        => $cat?->icon,
                'specialties' => $providerCategory->specialties ?? [],
                'price_type'  => $providerCategory->price_type,
                'price_from'  => $providerCategory->price_from !== null ? (float) $providerCategory->price_from : null,
                'price_to'    => $providerCategory->price_to !== null ? (float) $providerCategory->price_to : null,
                'is_active'   => (bool) $providerCategory->is_active,
            ];
        }) : [];

        // Service Areas
        $serviceAreasData = $this->relationLoaded('serviceAreas') ? $this->serviceAreas->map(function ($area) {
            return [
                'id'         => $area->id,
                'label'      => $area->label,
                'center_lat' => (float) $area->center_lat,
                'center_lng' => (float) $area->center_lng,
                'radius_km'  => (float) $area->radius_km,
            ];
        }) : [];

        // Schedules
        $schedulesData = $this->relationLoaded('schedules') ? $this->schedules->map(function ($sched) {
            return [
                'id'          => $sched->id,
                'day_of_week' => (int) $sched->day_of_week,
                'start_time'  => $sched->start_time,
                'end_time'    => $sched->end_time,
                'is_active'   => (bool) $sched->is_active,
            ];
        }) : [];

        // Distance calculation if client coordinates are present in request
        $distanceKm = null;
        $clientLat = $request->input('lat') ?? $request->input('client_lat');
        $clientLng = $request->input('lng') ?? $request->input('client_lng');
        if ($clientLat !== null && $clientLng !== null && $this->base_lat !== null && $this->base_lng !== null) {
            $distanceKm = $this->calculateDistance(
                (float) $clientLat,
                (float) $clientLng,
                (float) $this->base_lat,
                (float) $this->base_lng
            );
        }

        $zone = \App\Domain\Location\Services\LocationPresenter::getApproximateZone(
            $this->base_address,
            $this->base_lat,
            $this->base_lng
        );

        // Fetch last 5 ratings/reviews for this provider
        $recentRatings = RatingModel::where('reviewed_id', $this->user_id)
            ->with('reviewer')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function ($rating) {
                return [
                    'id'              => $rating->id,
                    'score'           => (int) $rating->score,
                    'comment'         => $rating->comment,
                    'reviewer_name'   => $this->trimReviewerName($rating->reviewer?->name),
                    'reviewer_avatar' => $rating->reviewer?->avatar_url,
                    'created_at'      => $rating->created_at instanceof \DateTimeInterface
                        ? $rating->created_at->toISOString()
                        : (string) ($rating->created_at ?? now()->toISOString()),
                ];
            });

        $availabilityStatus = $this->availability_status instanceof AvailabilityStatus
            ? $this->availability_status->value
            : (string) $this->availability_status;

        return [
            'uuid'             => $user?->uuid,
            'name'             => $this->commercial_name ?: ($user?->name ?? 'Profesional'),
            'commercial_name'  => $this->commercial_name,
            'user_name'        => $user?->name,
            'avatar_url'       => $user?->avatar_url,
            'bio'              => $this->bio,
            'years_experience' => (int) $this->years_experience,
            'is_verified'      => (bool) $this->is_verified,
            'location'         => [
                'address'     => $zone['name'],
                'zone'        => $zone['name'],
                'distance_km' => $distanceKm,
            ],
            'availability'     => [
                'status'            => $availabilityStatus,
                'busy_until'        => $this->busy_until?->toISOString(),
                'next_available_at' => $this->next_available_at?->toISOString(),
            ],
            'reputation_stats' => [
                'avg_rating'           => ((int) $this->total_reviews) > 0 ? (float) $this->avg_rating : null,
                'total_reviews'        => (int) $this->total_reviews,
                'total_jobs_completed' => (int) $this->total_jobs_completed,
                'completion_rate'      => (float) $this->completion_rate,
                'cancellation_count'   => (int) $this->cancellation_count,
                'response_rate'        => (float) $this->response_rate,
                'avg_response_minutes' => (int) $this->avg_response_minutes,
            ],
            'categories'     => $categoriesData,
            'service_areas'  => $serviceAreasData,
            'schedules'      => $schedulesData,
            'reviews'        => $recentRatings,
            'recent_reviews' => $recentRatings,
        ];
    }

    protected function trimReviewerName(?string $fullName): string
    {
        if (empty($fullName)) {
            return 'Cliente';
        }

        $parts = array_values(array_filter(explode(' ', trim($fullName))));
        if (count($parts) <= 1) {
            return $parts[0] ?? 'Cliente';
        }

        $firstName = $parts[0];
        $lastName = $parts[count($parts) - 1];
        $initial = mb_strtoupper(mb_substr($lastName, 0, 1));

        return "{$firstName} {$initial}.";
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($earthRadius * $c, 1);
    }
}
