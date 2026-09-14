<?php

namespace Tests\Feature\E2E;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $client;
    protected UserModel $providerUser1;
    protected ProviderProfileModel $providerProfile1;
    protected UserModel $providerUser2;
    protected ProviderProfileModel $providerProfile2;
    protected CategoryModel $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);

        $this->client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Mariana',
            'email' => 'mariana@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->client->assignRole('client');

        $this->category = CategoryModel::where('slug', 'plomeria')->first()
            ?? CategoryModel::first();

        // Provider 1 (Verified)
        $this->providerUser1 = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Gonzalo Plomero',
            'email' => 'gonzalo@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->providerUser1->assignRole('provider');

        $this->providerProfile1 = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->providerUser1->id,
            'category_id' => $this->category->id,
            'bio' => 'Plomero matriculado con experiencia',
            'is_verified' => true,
            'status' => ProviderProfileStatus::Verified,
            'avg_rating' => 4.9,
            'total_reviews' => 15,
            'availability_status' => 'available',
            'coverage_radius_km' => 20,
            'base_lat' => -34.6037,
            'base_lng' => -58.3816,
            'base_address' => 'Balvanera, CABA',
        ]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $this->providerProfile1->id,
            'overall_verification_status' => 'approved',
        ]);

        ProviderCategoryModel::create([
            'provider_id' => $this->providerProfile1->id,
            'category_id' => $this->category->id,
            'specialties' => ['destapaciones', 'cañerias'],
            'price_type' => 'fixed',
            'price_from' => 6000,
            'price_to' => 28000,
            'is_active' => true,
        ]);

        // Provider 2 (Competitor / Unassigned)
        $this->providerUser2 = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Esteban Plomero',
            'email' => 'esteban@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->providerUser2->assignRole('provider');

        $this->providerProfile2 = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->providerUser2->id,
            'category_id' => $this->category->id,
            'bio' => 'Plomero independiente',
            'is_verified' => true,
            'status' => ProviderProfileStatus::Verified,
            'avg_rating' => 4.5,
            'total_reviews' => 8,
            'availability_status' => 'available',
            'coverage_radius_km' => 15,
            'base_lat' => -34.6037,
            'base_lng' => -58.3816,
            'base_address' => 'Palermo, CABA',
        ]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $this->providerProfile2->id,
            'overall_verification_status' => 'approved',
        ]);
    }

    private function createMatchedServiceRequest(): array
    {
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->category->id,
            'raw_prompt' => 'Filtración en bajo mesada',
            'status' => 'matching_active',
            'location_lat' => -34.6037,
            'location_lng' => -58.3816,
            'location_address' => 'Av. Corrientes 1500, Buenos Aires',
        ]);

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        $card = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $this->providerProfile1->id,
            'rank_position' => 1,
            'score_total' => 0.96,
            'card_status' => 'shown',
        ]);

        return [$serviceRequest, $session, $card];
    }

    /**
     * C1. Provider con KYC verified recibe asignación
     */
    public function test_c1_verified_provider_receives_assignment(): void
    {
        [$serviceRequest, $session, $card] = $this->createMatchedServiceRequest();

        Sanctum::actingAs($this->providerUser1);
        $res = $this->getJson('/api/v1/provider/work-requests');

        $res->assertStatus(200);
        $items = $res->json('data');
        $this->assertNotEmpty($items);

        $match = collect($items)->firstWhere('id', $serviceRequest->uuid);
        $this->assertNotNull($match, 'La solicitud debe figurar en la bandeja del proveedor asignado');
        $this->assertEquals('Filtración en bajo mesada', $match['raw_prompt']);
    }

    /**
     * C2. Provider visualiza detalles del work
     */
    public function test_c2_provider_views_work_details(): void
    {
        [$serviceRequest, $session, $card] = $this->createMatchedServiceRequest();

        Sanctum::actingAs($this->providerUser1);
        $res = $this->getJson('/api/v1/provider/work-requests');

        $res->assertStatus(200);
        $item = collect($res->json('data'))->firstWhere('id', $serviceRequest->uuid);

        $this->assertNotNull($item);
        $this->assertArrayHasKey('client_name', $item);
        $this->assertArrayHasKey('category', $item);
        $this->assertArrayHasKey('raw_prompt', $item);
        $this->assertArrayHasKey('estimated_duration_min', $item);
        // Geo privacy: masked zone (is_approximate = true)
        $this->assertTrue($item['is_approximate'] ?? false);
        $this->assertNotEmpty($item['location_address'] ?? $item['address']);
    }

    /**
     * C3. Provider acepta work
     */
    public function test_c3_provider_accepts_work(): void
    {
        [$serviceRequest, $session, $card] = $this->createMatchedServiceRequest();

        Sanctum::actingAs($this->providerUser1);
        $res = $this->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm", [
            'estimated_duration_min' => 90,
            'scheduled_at' => now()->addDays(1)->toISOString(),
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.estimated_duration_min', 90);

        $work = WorkModel::where('service_request_id', $serviceRequest->id)->first();
        $this->assertNotNull($work);
        $this->assertEquals($this->providerProfile1->id, $work->provider_id);
        $this->assertEquals('confirmed', $work->status->value);
    }

    /**
     * C4. Provider intenta aceptar work nuevamente (idempotencia)
     */
    public function test_c4_provider_duplicate_accept_handled_idempotently(): void
    {
        [$serviceRequest, $session, $card] = $this->createMatchedServiceRequest();

        Sanctum::actingAs($this->providerUser1);
        // Primera aceptación
        $res1 = $this->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm", [
            'estimated_duration_min' => 90,
        ]);
        $res1->assertStatus(200);
        $workId1 = $res1->json('data.work_id');

        // Segunda aceptación por el mismo proveedor
        $res2 = $this->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm", [
            'estimated_duration_min' => 90,
        ]);
        $res2->assertStatus(200);
        $workId2 = $res2->json('data.work_id');

        // Idempotente: mismo work_id, sin duplicar registros en base de datos
        $this->assertEquals($workId1, $workId2);
        $this->assertEquals(1, WorkModel::where('service_request_id', $serviceRequest->id)->count());
    }

    /**
     * C5. Otro provider intenta aceptar mismo work -> 403 / 422 Forbidden
     */
    public function test_c5_unassigned_provider_cannot_accept_work(): void
    {
        [$serviceRequest, $session, $card] = $this->createMatchedServiceRequest();

        // Proveedor 1 confirma y toma el trabajo
        Sanctum::actingAs($this->providerUser1);
        $res1 = $this->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm");
        $res1->assertStatus(200);

        // Proveedor 2 intenta confirmar el trabajo ya tomado
        Sanctum::actingAs($this->providerUser2);
        $res2 = $this->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm");

        // No debe permitir al proveedor 2 tomar el trabajo (403 Forbidden o 409/422 Unprocessable)
        $this->assertTrue(
            in_array($res2->status(), [403, 409, 422], true),
            "Expected 403, 409 or 422 but got {$res2->status()}"
        );

        // Verificar que el proveedor 2 no fue asignado
        $work = WorkModel::where('service_request_id', $serviceRequest->id)->first();
        $this->assertEquals($this->providerProfile1->id, $work->provider_id);
    }
}
