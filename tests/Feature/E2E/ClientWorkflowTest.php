<?php

namespace Tests\Feature\E2E;

use App\Domain\Offers\Events\OfferAccepted;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $client;
    protected UserModel $providerUser;
    protected ProviderProfileModel $providerProfile;
    protected CategoryModel $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);

        $this->client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Test',
            'email' => 'client_e2e@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->client->assignRole('client');

        $this->category = CategoryModel::where('slug', 'plomeria')->first()
            ?? CategoryModel::first();

        $this->providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Mario Plomero',
            'email' => 'mario_plomero@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->providerUser->assignRole('provider');

        $this->providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->providerUser->id,
            'category_id' => $this->category->id,
            'bio' => 'Especialista en cañerías y gas',
            'is_verified' => true,
            'status' => ProviderProfileStatus::Verified,
            'avg_rating' => 4.9,
            'total_reviews' => 24,
            'availability_status' => 'available',
            'coverage_radius_km' => 20,
            'base_lat' => -34.6037,
            'base_lng' => -58.3816,
            'base_address' => 'Calle Principal 123, Buenos Aires',
        ]);

        ProviderCategoryModel::create([
            'provider_id' => $this->providerProfile->id,
            'category_id' => $this->category->id,
            'specialties' => ['cañerias', 'perdidas', 'griferia'],
            'price_type' => 'fixed',
            'price_from' => 5000,
            'price_to' => 25000,
            'is_active' => true,
        ]);
    }

    /**
     * A1. Cliente NO autenticado intenta crear solicitud -> 401 Unauthorized
     */
    public function test_a1_unauthenticated_client_cannot_create_request(): void
    {
        $response = $this->postJson('/api/v1/requests', [
            'prompt' => 'Reparación de caño urgente',
            'category_slug' => $this->category->slug,
        ]);

        $response->assertStatus(401);
    }

    /**
     * A2. Cliente autenticado crea solicitud válida -> 201 Created
     */
    public function test_a2_authenticated_client_creates_valid_service_request(): void
    {
        Sanctum::actingAs($this->client);

        $payload = [
            'prompt' => 'Reparación de caño en cocina con pérdida de agua',
            'category_id' => $this->category->id,
            'category_slug' => $this->category->slug,
            'urgency' => 'immediate',
            'is_remote' => false,
            'location' => [
                'address' => 'Calle Principal 123, Buenos Aires',
                'lat' => -34.6037,
                'lng' => -58.3816,
            ],
            'parsed_intent' => [
                'raw_intent' => 'Reparación de caño',
                'confidence' => 0.95,
                'detected_keywords' => ['reparación', 'caño'],
            ],
        ];

        $response = $this->postJson('/api/v1/requests', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_survey')
            ->assertJsonPath('data.category.slug', $this->category->slug);

        $responseData = $response->json('data');
        $this->assertNotEmpty($responseData['id'], 'El ID/UUID de la solicitud debe existir');

        $this->assertDatabaseHas('service_requests', [
            'uuid' => $responseData['id'],
            'client_id' => $this->client->id,
            'category_id' => $this->category->id,
            'raw_prompt' => 'Reparación de caño en cocina con pérdida de agua',
        ]);
    }

    /**
     * A3. Sistema busca providers disponibles en zona -> 200 OK
     */
    public function test_a3_system_finds_available_providers_in_zone(): void
    {
        $response = $this->getJson("/api/v1/providers?category={$this->category->slug}&availability=available&lat=-34.6037&lng=-58.3816");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'name',
                        'avg_rating',
                        'availability_status',
                    ]
                ]
            ]);

        $providers = $response->json('data');
        $this->assertIsArray($providers);
        $this->assertNotEmpty($providers, 'Debe retornar al menos un proveedor en la zona geográfica');

        $found = collect($providers)->first(function ($p) {
            return ($p['id'] ?? null) === $this->providerProfile->uuid
                || ($p['uuid'] ?? null) === $this->providerUser->uuid
                || ($p['name'] ?? null) === 'Mario Plomero';
        });
        $this->assertNotNull($found, 'El proveedor configurado debe figurar en los resultados de zona');
        $this->assertEquals('available', $found['availability_status']);
    }

    /**
     * A4. Sistema asigna provider automático o confirmación -> 200 OK
     */
    public function test_a4_provider_assigned_to_work_successfully(): void
    {
        // 1. Cliente crea la solicitud
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->category->id,
            'raw_prompt' => 'Reparación urgente de caño',
            'status' => 'matching_active',
            'location_lat' => -34.6037,
            'location_lng' => -58.3816,
            'location_address' => 'Calle Principal 123, Buenos Aires',
        ]);

        // 2. Sesión de matching y tarjeta para el proveedor
        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        $card = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $this->providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.98,
            'card_status' => 'shown',
        ]);

        // 3. Proveedor confirma la solicitud
        Sanctum::actingAs($this->providerUser);
        $response = $this->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm", [
            'estimated_duration_min' => 90,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');

        // 4. Verificar que se creó el Work y asignó al proveedor
        $work = WorkModel::where('service_request_id', $serviceRequest->id)->first();
        $this->assertNotNull($work, 'El registro de Work debe haberse creado en la base de datos');
        $this->assertEquals($this->providerProfile->id, $work->provider_id);
        $this->assertEquals($this->client->id, $work->client_id);
        $this->assertEquals('confirmed', $work->status->value);
        $this->assertNotNull($work->confirmed_at);
    }

    /**
     * A5. Provider recibe notificación / evento de asignación y logging
     */
    public function test_a5_provider_assignment_dispatches_notification_or_log(): void
    {
        Event::fake([OfferAccepted::class]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->category->id,
            'raw_prompt' => 'Cambio de llave de paso',
            'status' => 'matching_active',
        ]);

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $this->providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.95,
            'card_status' => 'shown',
        ]);

        Sanctum::actingAs($this->providerUser);
        $response = $this->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm", [
            'estimated_duration_min' => 60,
        ]);

        $response->assertStatus(200);

        // Verificar que se despacha el evento OfferAccepted
        Event::assertDispatched(OfferAccepted::class, function ($event) use ($serviceRequest) {
            return $event->offer->service_request_id === $serviceRequest->id
                && $event->offer->provider_id === $this->providerProfile->id;
        });
    }
}
