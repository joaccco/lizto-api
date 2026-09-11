<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Models\ProfessionalMVU;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderPublicCatalogPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedProvider(
        string $name = 'Lucas Romero',
        string $email = 'lucas.romero.private@example.com',
        string $phone = '+5491199887766',
        ?string $address = 'Thames 1842, Piso 3B, Palermo',
        ?float $lat = -34.5889123,
        ?float $lng = -58.4306456
    ): ProviderProfileModel {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => bcrypt('password'),
        ]);

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
            'is_verified' => true,
            'base_address' => $address,
            'base_lat' => $lat,
            'base_lng' => $lng,
        ]);

        $identity = Identity::create([
            'user_id' => $user->id,
            'status' => 'approved',
        ]);

        ProfessionalMVU::create([
            'provider_id' => $profile->id,
            'identity_id' => $identity->id,
            'overall_verification_status' => 'approved',
            'mvu_status' => 'verified',
            'requirements_evaluation' => ['rules_passed' => true],
            'last_evaluated_at' => now(),
        ]);

        return $profile;
    }

    public function test_public_catalog_listing_does_not_leak_email_or_phone(): void
    {
        $this->createVerifiedProvider('Lucas Romero', 'secret.lucas@example.com', '+5491199887766');

        $response = $this->getJson('/api/v1/providers');

        $response->assertStatus(200);

        $response->assertJsonMissingPath('data.0.email');
        $response->assertJsonMissingPath('data.0.phone');
        $response->assertJsonMissing(['email' => 'secret.lucas@example.com']);
        $response->assertJsonMissing(['phone' => '+5491199887766']);
    }

    public function test_public_catalog_listing_does_not_contain_exact_address_or_coordinates(): void
    {
        $this->createVerifiedProvider(
            'Roberto Medina',
            'roberto@example.com',
            '+54911112233',
            'Thames 1842, Piso 3B, Palermo',
            -34.5889123,
            -58.4306456
        );

        $response = $this->getJson('/api/v1/providers');

        $response->assertStatus(200);

        // Must not contain exact home address nor exact coordinates
        $response->assertJsonMissing(['address' => 'Thames 1842, Piso 3B, Palermo']);
        $response->assertJsonMissing(['lat' => -34.5889123]);
        $response->assertJsonMissing(['lng' => -58.4306456]);

        // Instead, approximate zone should be shown
        $data = $response->json('data.0');
        $this->assertEquals('Palermo', $data['location']['zone']);
        $this->assertEquals('Palermo', $data['location']['address']);
    }

    public function test_public_catalog_listing_does_not_fabricate_centro_when_no_address(): void
    {
        $this->createVerifiedProvider(
            'Carlos SinDireccion',
            'carlos.sindireccion@example.com',
            '+54911445566',
            null,
            null,
            null
        );

        $response = $this->getJson('/api/v1/providers');

        $response->assertStatus(200);

        $response->assertJsonMissing(['address' => 'Centro']);
        $response->assertJsonMissing(['base_address' => 'Centro']);

        $data = $response->json('data.0');
        $this->assertNull($data['location']['address']);
        $this->assertNull($data['location']['zone']);
        $this->assertNull($data['base_address']);
    }
}
