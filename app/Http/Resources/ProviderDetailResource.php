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
                    'reviewer_name'   => $rating->reviewer?->name ?? 'Cliente',
                    'reviewer_avatar' => $rating->reviewer?->avatar_url,
                    'created_at'      => $rating->created_at?->toISOString(),
                ];
            });

        $availabilityStatus = $this->availability_status instanceof AvailabilityStatus
            ? $this->availability_status->value
            : (string) $this->availability_status;

        return [
            'uuid'             => $user?->uuid,
            'name'             => $user?->name,
            'email'            => $user?->email,
            'phone'            => $user?->phone,
            'avatar_url'       => $user?->avatar_url,
            'bio'              => $this->bio,
            'years_experience' => (int) $this->years_experience,
            'is_verified'      => (bool) $this->is_verified,
            'location'         => [
                'address' => $this->base_address,
                'lat'     => $this->base_lat !== null ? (float) $this->base_lat : null,
                'lng'     => $this->base_lng !== null ? (float) $this->base_lng : null,
            ],
            'availability'     => [
                'status'            => $availabilityStatus,
                'busy_until'        => $this->busy_until?->toISOString(),
                'next_available_at' => $this->next_available_at?->toISOString(),
            ],
            'reputation_stats' => [
                'avg_rating'           => (float) $this->avg_rating,
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
            'recent_reviews' => $recentRatings,
        ];
    }
}
