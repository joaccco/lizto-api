<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->uuid,
            'name'       => $this->name,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'avatar_url' => $this->avatar_url,
            'status'     => $this->status,
            'roles'      => $this->getRoleNames(),
            'has_provider_profile' => $this->providerProfile !== null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
