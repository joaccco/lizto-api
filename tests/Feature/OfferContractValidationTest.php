<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfferContractValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createVerifiedProviderAndRequest(): array
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Contrato',
            'email' => 'client_contract_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::first();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Servicio para ofertas',
            'status' => 'pending_matching',
        ]);

        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Verificado Ofertas',
            'email' => 'provider_contract_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);
        $providerProfile->categories()->create(['category_id' => $category->id]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $providerProfile->id,
            'overall_verification_status' => 'approved',
        ]);

        return [$providerUser, $sr];
    }

    public function test_quoted_mode_without_exact_price_or_with_range_is_rejected(): void
    {
        [$providerUser, $sr] = $this->createVerifiedProviderAndRequest();

        // 1. Quoted without exact price -> 422
        $res1 = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'quoted',
            ]);
        $res1->assertStatus(422);

        // 2. Quoted with price range -> 422
        $res2 = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'quoted',
                'proposed_price' => 15000,
                'price_min' => 10000,
                'price_max' => 20000,
            ]);
        $res2->assertStatus(422);
    }

    public function test_requires_visit_mode_without_range_or_with_exact_price_is_rejected(): void
    {
        [$providerUser, $sr] = $this->createVerifiedProviderAndRequest();

        // 1. Requires visit without range -> 422
        $res1 = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'requires_visit',
            ]);
        $res1->assertStatus(422);

        // 2. Requires visit with exact proposed_price -> 422
        $res2 = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'requires_visit',
                'proposed_price' => 15000,
                'price_min' => 10000,
                'price_max' => 20000,
            ]);
        $res2->assertStatus(422);
    }

    public function test_price_min_greater_than_price_max_is_rejected(): void
    {
        [$providerUser, $sr] = $this->createVerifiedProviderAndRequest();

        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'requires_visit',
                'price_min' => 25000,
                'price_max' => 15000,
            ]);

        $response->assertStatus(422);
    }

    public function test_unknown_field_in_request_body_is_rejected(): void
    {
        [$providerUser, $sr] = $this->createVerifiedProviderAndRequest();

        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'quoted',
                'proposed_price' => 18000,
                'unknown_hacker_field' => 'injection_test',
            ]);

        $response->assertStatus(422);
    }

    public function test_offer_notes_and_range_are_persisted_and_read_back(): void
    {
        [$providerUser, $sr] = $this->createVerifiedProviderAndRequest();

        $notes = 'Visita para evaluar reemplazo completo de cañerías antiguas.';
        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/service-requests/{$sr->uuid}/offers", [
                'pricing_mode' => 'requires_visit',
                'price_min' => 1200000, // 12000 ARS in cents
                'price_max' => 2500000, // 25000 ARS in cents
                'notes' => $notes,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.pricing_mode', 'requires_visit')
            ->assertJsonPath('data.price_min', 1200000)
            ->assertJsonPath('data.price_max', 2500000)
            ->assertJsonPath('data.notes', $notes);

        $offer = OfferModel::where('uuid', $response->json('data.id'))->first();
        $this->assertNotNull($offer);
        $this->assertEquals(1200000, $offer->price_min);
        $this->assertEquals(2500000, $offer->price_max);
        $this->assertEquals($notes, $offer->notes);
    }
}
