<?php

namespace Database\Factories;

use App\Domain\Providers\Enums\AvailabilityStatus;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ProviderServiceAreaModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\Identity;
use App\Models\ProfessionalMVU;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProviderProfileModel>
 */
class ProviderProfileFactory extends Factory
{
    protected $model = ProviderProfileModel::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => function () {
                $user = UserModel::factory()->create();
                $user->assignRole('provider');
                return $user->id;
            },
            'bio' => fake()->paragraph(2),
            'years_experience' => fake()->numberBetween(1, 15),
            'avg_rating' => 4.8,
            'total_reviews' => 15,
            'total_jobs_completed' => 25,
            'completion_rate' => 98.0,
            'response_rate' => 95.0,
            'avg_response_minutes' => 15,
            'base_lat' => -27.4692,
            'base_lng' => -58.8306,
            'base_address' => 'Corrientes, Argentina',
            'availability_status' => AvailabilityStatus::Available,
        ];
    }

    /**
     * Estado habilitado: crea Identity y ProfessionalMVU aprobados,
     * garantizando que el profesional cumpla la invariante de búsqueda y matching.
     */
    public function enabled(): static
    {
        return $this->afterCreating(function (ProviderProfileModel $profile) {
            $user = $profile->user;
            if ($user && !$user->hasRole('provider')) {
                $user->assignRole('provider');
            }

            $identity = Identity::firstOrCreate(
                ['user_id' => $profile->user_id],
                [
                    'dni' => '20' . str_pad((string) $profile->user_id, 6, '0', STR_PAD_LEFT),
                    'firstname' => $profile->first_name ?: ($user?->name ?: 'Profesional'),
                    'lastname' => $profile->last_name ?: 'Habilitado',
                    'status' => 'approved',
                    'verified_at' => now(),
                    'verified_by' => 'factory',
                ]
            );

            ProfessionalMVU::firstOrCreate(
                ['provider_id' => $profile->id],
                [
                    'identity_id' => $identity->id,
                    'identity_verified_at' => now(),
                    'antecedentes_status' => 'approved',
                    'antecedentes_cert_uploaded_at' => now(),
                    'matrícula_number' => 'MAT-FAC-' . $profile->id,
                    'matrícula_verified_at' => now(),
                    'skills_verified' => true,
                    'overall_verification_status' => 'approved',
                ]
            );
        });
    }

    /**
     * Alias de enabled.
     */
    public function verified(): static
    {
        return $this->enabled();
    }
}
