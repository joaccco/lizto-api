<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MVUResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $provider = $this->provider;
        $identity = $this->identity;

        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'provider_uuid' => $provider?->uuid,
            'provider_name' => $provider ? trim(($provider->first_name ?? '') . ' ' . ($provider->last_name ?? '')) : null,
            'commercial_name' => $provider?->commercial_name,
            'categories' => $provider?->categories?->pluck('category.name')->filter()->values() ?? [],
            'years_experience' => $provider?->years_experience,
            'identity' => $identity ? [
                'id' => $identity->id,
                'status' => $identity->status,
                'firstname' => $identity->firstname,
                'lastname' => $identity->lastname,
                'verified_at' => $identity->verified_at?->toISOString(),
                'document_front_url' => $identity->document_front_url,
                'selfie_url' => $identity->selfie_url,
            ] : null,
            'antecedentes' => [
                'status' => $this->antecedentes_status,
                'cert_url' => $this->antecedentes_cert_url,
                'uploaded_at' => $this->antecedentes_cert_uploaded_at?->toISOString(),
            ],
            'matrícula' => [
                'number' => $this->matrícula_number ? '••••' . substr((string) $this->matrícula_number, -4) : null,
                'verified_at' => $this->matrícula_verified_at?->toISOString(),
            ],
            'skills_verified' => $this->skills_verified,
            'overall_verification_status' => $this->overall_verification_status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
