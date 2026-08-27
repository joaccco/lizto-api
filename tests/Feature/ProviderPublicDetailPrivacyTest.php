<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\RatingModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderPublicDetailPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $name = 'Usuario', ?string $email = null, string $phone = '1122334455'): UserModel
    {
        return UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email ?? ('user_' . Str::random(6) . '@example.com'),
            'phone' => $phone,
            'password' => bcrypt('password'),
        ]);
    }

    public function test_public_profile_response_does_not_contain_email_or_phone(): void
    {
        $providerUser = $this->createUser('Lucas Romero', 'lucas.romero.private@example.com', '+5491199887766');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'base_address' => 'Thames 1842, Piso 3B, Palermo',
            'base_lat' => -34.5889123,
            'base_lng' => -58.4306456,
        ]);

        $response = $this->getJson("/api/v1/providers/{$profile->uuid}");

        $response->assertStatus(200);

        // Must NOT contain email or phone
        $response->assertJsonMissingPath('data.email');
        $response->assertJsonMissingPath('data.phone');
        $response->assertJsonMissing(['email' => 'lucas.romero.private@example.com']);
        $response->assertJsonMissing(['phone' => '+5491199887766']);
    }

    public function test_public_profile_response_does_not_contain_exact_coordinates_or_home_address(): void
    {
        $providerUser = $this->createUser('Roberto Medina');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'base_address' => 'Thames 1842, Piso 3B, Palermo, CABA',
            'base_lat' => -34.5889123,
            'base_lng' => -58.4306456,
        ]);

        $response = $this->getJson("/api/v1/providers/{$profile->uuid}");

        $response->assertStatus(200);

        // Must NOT contain exact home address nor exact coordinates
        $response->assertJsonMissing(['address' => 'Thames 1842, Piso 3B, Palermo, CABA']);
        $response->assertJsonMissing(['lat' => -34.5889123]);
        $response->assertJsonMissing(['lng' => -58.4306456]);
    }

    public function test_public_profile_response_includes_approximate_zone_and_optional_distance(): void
    {
        $providerUser = $this->createUser('Ana Gómez');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'base_address' => 'Palermo, CABA',
            'base_lat' => -34.5889,
            'base_lng' => -58.4306,
        ]);

        // Request with client location lat & lng query parameters
        $response = $this->getJson("/api/v1/providers/{$profile->uuid}?lat=-34.5800&lng=-58.4300");

        $response->assertStatus(200);
        $response->assertJsonPath('data.location.address', 'Palermo');
        $this->assertNotNull($response->json('data.location.distance_km'));
        $this->assertIsNumeric($response->json('data.location.distance_km'));
    }

    public function test_reviewer_names_arrive_trimmed_from_backend(): void
    {
        $providerUser = $this->createUser('Carlos Peralta');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'avg_rating' => 5.0,
            'total_reviews' => 1,
        ]);

        $clientUser = $this->createUser('María Florencia García');

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $clientUser->id,
            'raw_prompt' => 'Reparación de cerradura',
            'status' => 'completed',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $clientUser->id,
            'provider_id' => $profile->id,
            'status' => \App\Domain\Works\Enums\WorkStatus::Completed,
        ]);

        RatingModel::create([
            'work_id' => $work->id,
            'reviewer_id' => $clientUser->id,
            'reviewed_id' => $providerUser->id,
            'direction' => 'client_to_provider',
            'score' => 5,
            'comment' => 'Excelente profesional.',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/providers/{$profile->uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.recent_reviews.0.reviewer_name', 'María G.');
        $response->assertJsonMissing(['reviewer_name' => 'María Florencia García']);
    }
}
