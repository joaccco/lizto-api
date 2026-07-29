<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $provider = $this->provider;
        $user = $provider?->user;
        $snapshot = $this->snapshot ?? [];

        return [
            'card_id' => $this->id,
            'rank_position' => $this->rank_position,
            'score_total' => (float) $this->score_total,
            'score_breakdown' => $this->score_breakdown,
            'card_status' => $this->card_status instanceof \BackedEnum ? $this->card_status->value : $this->card_status,
            'provider' => [
                'uuid' => $user?->uuid ?? (string)$provider?->id,
                'name' => $user?->name ?? 'Proveedor',
                'avatar_url' => $user?->avatar_url,
                'bio' => $provider?->bio,
                'is_verified' => $provider?->is_verified ?? false,
                'avg_rating' => (float) ($snapshot['avg_rating'] ?? $provider?->avg_rating ?? 5.0),
                'total_reviews' => (int) ($snapshot['total_reviews'] ?? $provider?->total_reviews ?? 0),
                'price_from' => $snapshot['price_from'] ?? null,
                'availability_status' => $snapshot['availability_status'] ?? 'available',
                'distance_km' => $snapshot['distance_km'] ?? null,
                'eta_minutes' => $snapshot['eta_minutes'] ?? null,
            ],
        ];
    }
}
