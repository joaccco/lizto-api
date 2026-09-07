<?php

namespace Tests\Feature\E2E;

use App\Domain\Location\Events\ProviderLocationUpdated;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderLocationModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeoTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $client;
    protected UserModel $providerUser;
    protected UserModel $unauthorizedUser;
    protected ProviderProfileModel $providerProfile;
    protected CategoryModel $category;
    protected ServiceRequestModel $serviceRequest;
    protected WorkModel $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
        RateLimiter::clear('provider-location:*');

        $this->client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Geo',
            'email' => 'cliente_geo@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->client->assignRole('client');

        $this->category = CategoryModel::first();

        $this->providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Tracker',
            'email' => 'provider_tracker@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->providerUser->assignRole('provider');

        $this->providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->providerUser->id,
            'category_id' => $this->category->id,
            'is_verified' => true,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
        ]);

        $this->unauthorizedUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Intruder User',
            'email' => 'intruder@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->unauthorizedUser->assignRole('client');

        $this->serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->category->id,
            'raw_prompt' => 'Urgencia plomería con tracking',
            'location_lat' => -34.5889, // Palermo destination
            'location_lng' => -58.4305,
            'location_address' => 'Palermo, Buenos Aires',
            'status' => 'matching_active',
        ]);

        $this->work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'confirmed_at' => now(),
            'work_lat' => -34.5889,
            'work_lng' => -58.4305,
            'work_address' => 'Palermo, Buenos Aires',
        ]);
    }

    /**
     * T1. Provider emits location -> 200 OK + stored in DB + event dispatched
     */
    public function test_t1_provider_emits_location_successfully(): void
    {
        Event::fake([ProviderLocationUpdated::class]);
        Sanctum::actingAs($this->providerUser);

        $payload = [
            'latitude' => -34.6037,
            'longitude' => -58.3816,
            'accuracy_meters' => 8,
            'heading' => 45,
            'speed_kmh' => 25.5,
        ];

        $response = $this->postJson('/api/v1/providers/me/location', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('provider_id', $this->providerProfile->id)
            ->assertJsonPath('heading', 45)
            ->assertJsonPath('speed_kmh', 25.5);

        $this->assertDatabaseHas('provider_locations', [
            'provider_id' => $this->providerProfile->id,
            'accuracy_meters' => 8,
            'heading' => 45,
        ]);

        Event::assertDispatched(ProviderLocationUpdated::class, function ($event) {
            return $event->location->provider_id === $this->providerProfile->id;
        });
    }

    /**
     * T2. Client views provider location -> 200 OK + approximate_zone only
     */
    public function test_t2_client_views_provider_location_with_approximate_zone(): void
    {
        // Guardar ubicación previa del proveedor
        ProviderLocationModel::create([
            'provider_id' => $this->providerProfile->id,
            'latitude' => -34.5889,
            'longitude' => -58.4305,
            'accuracy_meters' => 10,
            'heading' => 90,
            'speed_kmh' => 30.0,
        ]);

        Sanctum::actingAs($this->client);
        $response = $this->getJson("/api/v1/works/{$this->work->uuid}/provider-location");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'provider_id',
                    'approximate_zone',
                    'approximate_center',
                    'heading',
                    'speed_kmh',
                    'estimated_arrival_minutes',
                    'last_update',
                    'is_approximate',
                ],
            ]);

        $data = $response->json('data');
        $this->assertTrue($data['is_approximate']);
        $this->assertNotEmpty($data['approximate_zone']);
        $this->assertArrayHasKey('latitude', $data['approximate_center']);
        $this->assertArrayHasKey('longitude', $data['approximate_center']);
    }

    /**
     * T3. Non-client cannot view location -> 403 Forbidden
     */
    public function test_t3_unauthorized_user_cannot_view_location(): void
    {
        ProviderLocationModel::create([
            'provider_id' => $this->providerProfile->id,
            'latitude' => -34.6037,
            'longitude' => -58.3816,
        ]);

        Sanctum::actingAs($this->unauthorizedUser);
        $response = $this->getJson("/api/v1/works/{$this->work->uuid}/provider-location");

        $response->assertStatus(403);
    }

    /**
     * T4. Location throttling (< 5 sec) -> 429 Too Many Requests
     */
    public function test_t4_location_emission_is_throttled(): void
    {
        Sanctum::actingAs($this->providerUser);

        // Primer intento -> 200
        $res1 = $this->postJson('/api/v1/providers/me/location', [
            'latitude' => -34.6037,
            'longitude' => -58.3816,
        ]);
        $res1->assertStatus(200);

        // Segundo intento inmediato -> 429
        $res2 = $this->postJson('/api/v1/providers/me/location', [
            'latitude' => -34.6038,
            'longitude' => -58.3817,
        ]);
        $res2->assertStatus(429);
        $this->assertTrue($res2->headers->has('Retry-After'));
    }

    /**
     * T5. Exact coordinates NOT leaked to client (D-01 Geo-Privacy)
     */
    public function test_t5_exact_coordinates_are_not_leaked_to_client(): void
    {
        $exactLat = -34.6037123;
        $exactLng = -58.3816456;

        ProviderLocationModel::create([
            'provider_id' => $this->providerProfile->id,
            'latitude' => $exactLat,
            'longitude' => $exactLng,
            'accuracy_meters' => 5,
        ]);

        Sanctum::actingAs($this->client);
        $response = $this->getJson("/api/v1/works/{$this->work->uuid}/provider-location");

        $response->assertStatus(200);
        $data = $response->json('data');

        // El cliente NUNCA debe ver raw_latitude ni raw_longitude
        $this->assertArrayNotHasKey('raw_latitude', $data);
        $this->assertArrayNotHasKey('raw_longitude', $data);
        $this->assertNotEquals($exactLat, $data['approximate_center']['latitude']);
        $this->assertNotEquals($exactLng, $data['approximate_center']['longitude']);
    }

    /**
     * T6. Works with active status only (confirmed or in_progress)
     */
    public function test_t6_tracking_only_available_for_active_works(): void
    {
        ProviderLocationModel::create([
            'provider_id' => $this->providerProfile->id,
            'latitude' => -34.6037,
            'longitude' => -58.3816,
        ]);

        // Cambiar estado a cancelado
        $this->work->update(['status' => WorkStatus::Cancelled]);

        Sanctum::actingAs($this->client);
        $response = $this->getJson("/api/v1/works/{$this->work->uuid}/provider-location");

        $response->assertStatus(404);
    }

    /**
     * T7. ETA calculation accurate based on distance and speed
     */
    public function test_t7_eta_calculated_accurately(): void
    {
        // Proveedor ubicado a ~5km del destino, viajando a 30 km/h -> ETA ~10 min
        ProviderLocationModel::create([
            'provider_id' => $this->providerProfile->id,
            'latitude' => -34.6200,
            'longitude' => -58.4000,
            'speed_kmh' => 30.0,
        ]);

        Sanctum::actingAs($this->client);
        $response = $this->getJson("/api/v1/works/{$this->work->uuid}/provider-location");

        $response->assertStatus(200);
        $eta = $response->json('data.estimated_arrival_minutes');
        $this->assertIsInt($eta);
        $this->assertGreaterThan(0, $eta);
        $this->assertLessThan(60, $eta);
    }
}
