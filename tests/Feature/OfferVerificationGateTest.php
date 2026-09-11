<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ProviderServiceAreaModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\ProfessionalMVU;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEC-04 — Adversarial test: verifies that offer creation requires
 * an approved MVU, not just legacy profile flags.
 */
class OfferVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    /**
     * A professional with legacy flags ON but NO approved MVU must be rejected.
     */
    public function test_offer_rejected_when_legacy_flags_on_but_mvu_not_approved(): void
    {
        // Arrange: client + service request
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Client',
            'email' => 'client_offer_test@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);

        $category = CategoryModel::firstOrCreate(
            ['slug' => 'cerrajeria'],
            ['name' => 'Cerrajería', 'icon' => '🔑']
        );

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Necesito un cerrajero urgente',
            'status' => 'pending_matching',
            'urgency' => 'immediate',
            'client_lat' => -27.4692,
            'client_lng' => -58.8306,
        ]);

        // Arrange: provider with legacy flags BOTH ON — the exact bypass scenario
        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Bypass Offer Provider',
            'email' => 'bypass_offer@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $providerUser->assignRole('provider');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,  // Legacy flag ON
            'is_verified' => true,                         // Legacy flag ON
            'availability_status' => 'available',
            'base_lat' => -27.4692,
            'base_lng' => -58.8306,
            'base_address' => 'Corrientes',
            'avg_rating' => 4.8,
            'total_reviews' => 10,
        ]);

        ProviderCategoryModel::create([
            'provider_id' => $profile->id,
            'category_id' => $category->id,
            'specialties' => ['apertura'],
            'price_type' => 'fixed',
            'is_active' => true,
        ]);

        // Case 1: NO MVU record at all → MUST be rejected
        $response = $this->actingAs($providerUser)
            ->postJson("/api/v1/service-requests/{$serviceRequest->uuid}/offers", [
                'pricing_mode' => 'quoted',
                'proposed_price' => 15000,
            ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('identidad verificada', $response->json('message'));

        // Case 2: MVU exists but status is 'pending' → MUST be rejected
        $mvu = ProfessionalMVU::create([
            'provider_id' => $profile->id,
            'overall_verification_status' => 'pending',
        ]);

        $response2 = $this->actingAs($providerUser)
            ->postJson("/api/v1/service-requests/{$serviceRequest->uuid}/offers", [
                'pricing_mode' => 'quoted',
                'proposed_price' => 15000,
            ]);

        $response2->assertStatus(403);

        // Case 3: MVU approved → MUST be allowed
        $mvu->update(['overall_verification_status' => 'approved']);

        $response3 = $this->actingAs($providerUser)
            ->postJson("/api/v1/service-requests/{$serviceRequest->uuid}/offers", [
                'pricing_mode' => 'quoted',
                'proposed_price' => 15000,
            ]);

        $response3->assertStatus(201);
        $this->assertEquals('Oferta creada exitosamente.', $response3->json('message'));
    }
}
