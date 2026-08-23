<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfferProviderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    public function test_user_without_provider_profile_cannot_create_offer(): void
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Oferta',
            'email' => 'client_off_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::first();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Instalación eléctrica',
            'status' => 'pending_matching',
        ]);

        $userWithoutProfile = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario Sin Perfil',
            'email' => 'noprofile_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->actingAs($userWithoutProfile, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'quoted',
                'proposed_price' => 15000,
            ]);

        $response->assertStatus(403);
    }

    public function test_unverified_provider_profile_cannot_create_offer(): void
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Solicitante',
            'email' => 'client_req_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::first();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Cambio de térmicas',
            'status' => 'pending_matching',
        ]);

        $draftProviderUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor En Borrador',
            'email' => 'draft_provider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $draftProviderUser->id,
            'status' => ProviderProfileStatus::Draft,
            'is_verified' => false,
        ]);

        $response = $this->actingAs($draftProviderUser, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'quoted',
                'proposed_price' => 20000,
            ]);

        $response->assertStatus(403);
    }

    public function test_offer_is_never_assigned_to_different_provider(): void
    {
        $victimProviderUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Victima Suplantación',
            'email' => 'victim_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $victimProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $victimProviderUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        $attackerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Atacante Sin Perfil',
            'email' => 'attacker_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::first();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $attackerUser->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Plomería general',
            'status' => 'pending_matching',
        ]);

        $response = $this->actingAs($attackerUser, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'quoted',
                'proposed_price' => 12000,
            ]);

        $response->assertStatus(403);

        $this->assertEquals(0, \App\Infrastructure\Persistence\Eloquent\OfferModel::where('provider_id', $victimProfile->id)->count());
    }
}
