<?php

namespace App\Http\Resources;

use App\Domain\Providers\Enums\AvailabilityStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;

        // Calculate distance if lat and lng parameters are provided
        $distanceKm = null;
        if ($request->filled('lat') && $request->filled('lng') && $this->base_lat !== null && $this->base_lng !== null) {
            $distanceKm = $this->calculateDistance(
                (float) $request->input('lat'),
                (float) $request->input('lng'),
                (float) $this->base_lat,
                (float) $this->base_lng
            );
        }

        // Map provider categories
        $categoriesData = $this->relationLoaded('categories') ? $this->categories->map(function ($providerCategory) {
            $cat = $providerCategory->category;
            return [
                'id'          => $cat?->slug ?? (string) $providerCategory->category_id,
                'name'        => $cat?->name,
                'slug'        => $cat?->slug,
                'specialties' => $providerCategory->specialties ?? [],
                'price_type'  => $providerCategory->price_type,
                'price_from'  => $providerCategory->price_from !== null ? (float) $providerCategory->price_from : null,
                'price_to'    => $providerCategory->price_to !== null ? (float) $providerCategory->price_to : null,
            ];
        }) : [];

        // Determine price_from across categories
        $minPriceFrom = null;
        if ($this->relationLoaded('categories') && $this->categories->isNotEmpty()) {
            $prices = $this->categories->pluck('price_from')->filter(fn ($p) => $p !== null);
            if ($prices->isNotEmpty()) {
                $minPriceFrom = (float) $prices->min();
            }
        }

        $availabilityStatus = $this->availability_status instanceof AvailabilityStatus
            ? $this->availability_status->value
            : (string) $this->availability_status;

        return [
            'uuid'                 => $user?->uuid,
            'name'                 => $user?->name,
            'avatar_url'           => $user?->avatar_url,
            'bio'                  => $this->bio,
            'years_experience'     => (int) $this->years_experience,
            'is_verified'          => (bool) $this->is_verified,
            'avg_rating'           => (float) $this->avg_rating,
            'total_reviews'        => (int) $this->total_reviews,
            'total_jobs_completed' => (int) $this->total_jobs_completed,
            'price_from'           => $minPriceFrom,
            'availability_status'  => $availabilityStatus,
            'busy_until'           => $this->busy_until?->toISOString(),
            'next_available_at'    => $this->next_available_at?->toISOString(),
            'distance_km'          => $distanceKm,
            'categories'           => $categoriesData,
        ];
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
