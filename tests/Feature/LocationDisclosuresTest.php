<?php

namespace Tests\Feature;

use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationDisclosuresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    public function test_unconfirmed_provider_receives_approximate_zone_without_exact_address_or_coords(): void
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Ubicación',
            'email' => 'client_loc_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Ubicación',
            'email' => 'provider_loc_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::first();

        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'category_id' => $category->id,
            'bio' => 'Profesional de prueba',
            'is_verified' => true,
            'status' => 'verified',
        ]);
        $providerProfile->categories()->create(['category_id' => $category->id]);

        $exactAddress = 'Av. Santa Fe 1842, Piso 4 A, Palermo';
        $exactLat = -34.5889123;
        $exactLng = -58.4305456;

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de caño en Palermo',
            'location_address' => $exactAddress,
            'location_lat' => $exactLat,
            'location_lng' => $exactLng,
            'status' => 'pending_matching',
        ]);

        $response = $this->actingAs($providerUser, 'sanctum')
            ->getJson('/api/v1/provider/work-requests');

        $response->assertStatus(200);

        $item = collect($response->json('data'))->firstWhere('id', $serviceRequest->uuid);
        $this->assertNotNull($item);

        // Address must NOT contain exact street number or exact coords
        $this->assertStringNotContainsString('1842', $item['location_address']);
        $this->assertStringNotContainsString('Piso 4 A', $item['location_address']);
        $this->assertNotEquals($exactLat, $item['location_lat']);
        $this->assertNotEquals($exactLng, $item['location_lng']);
        $this->assertArrayHasKey('location_radius_meters', $item);
    }

    public function test_assigned_confirmed_provider_receives_exact_location(): void
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Confirmado',
            'email' => 'client_conf_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Confirmado',
            'email' => 'provider_conf_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::first();

        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'category_id' => $category->id,
            'bio' => 'Plomero',
            'is_verified' => true,
            'status' => 'verified',
        ]);
        $providerProfile->categories()->create(['category_id' => $category->id]);

        $exactAddress = 'Thames 1842, Palermo';
        $exactLat = -34.58891;
        $exactLng = -58.43054;

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Arreglo urgente',
            'location_address' => $exactAddress,
            'location_lat' => $exactLat,
            'location_lng' => $exactLng,
            'status' => 'provider_selected',
        ]);

        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'work_address' => $exactAddress,
            'work_lat' => $exactLat,
            'work_lng' => $exactLng,
        ]);

        $response = $this->actingAs($providerUser, 'sanctum')
            ->getJson('/api/v1/provider/work-requests');

        $response->assertStatus(200);

        $item = collect($response->json('data'))->firstWhere('id', $serviceRequest->uuid);
        $this->assertNotNull($item);
        $this->assertEquals($exactAddress, $item['location_address']);
        $this->assertEquals($exactLat, $item['location_lat']);
        $this->assertEquals($exactLng, $item['location_lng']);
    }

    public function test_two_requests_in_same_zone_return_same_centroid(): void
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Doble',
            'email' => 'client_doble_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Zona',
            'email' => 'provider_zona_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::first();

        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'category_id' => $category->id,
            'bio' => 'Cerrajero',
            'is_verified' => true,
            'status' => 'verified',
        ]);
        $providerProfile->categories()->create(['category_id' => $category->id]);

        $sr1 = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Apertura de puerta 1',
            'location_address' => 'Av. Santa Fe 1200, Palermo',
            'location_lat' => -34.5901,
            'location_lng' => -58.4201,
            'status' => 'pending_matching',
        ]);

        $sr2 = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Apertura de puerta 2',
            'location_address' => 'Gurruchaga 1500, Palermo',
            'location_lat' => -34.5855,
            'location_lng' => -58.4412,
            'status' => 'pending_matching',
        ]);

        $response = $this->actingAs($providerUser, 'sanctum')
            ->getJson('/api/v1/provider/work-requests');

        $response->assertStatus(200);

        $item1 = collect($response->json('data'))->firstWhere('id', $sr1->uuid);
        $item2 = collect($response->json('data'))->firstWhere('id', $sr2->uuid);

        $this->assertNotNull($item1);
        $this->assertNotNull($item2);
        $this->assertEquals($item1['location_lat'], $item2['location_lat']);
        $this->assertEquals($item1['location_lng'], $item2['location_lng']);
    }

    public function test_client_owner_sees_exact_location(): void
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Dueño',
            'email' => 'client_owner_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::first();
        $exactAddress = 'Corrientes 456, Piso 2, Centro';

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación eléctrica',
            'location_address' => $exactAddress,
            'location_lat' => -27.469212,
            'location_lng' => -58.830634,
            'status' => 'pending_matching',
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->getJson("/api/v1/requests/{$sr->uuid}");

        $response->assertStatus(200);
        $this->assertEquals($exactAddress, $response->json('data.address'));
    }
}
