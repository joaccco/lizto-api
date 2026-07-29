<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cards = $this->relationLoaded('cards') ? $this->cards : collect();

        return [
            'session_id' => $this->uuid,
            'total_providers' => $cards->count(),
            'cards' => MatchCardResource::collection($cards->take(3))->resolve(),
        ];
    }
}
