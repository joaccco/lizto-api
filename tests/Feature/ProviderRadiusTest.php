<?php

namespace Tests\Feature;

use App\Application\Matching\Actions\RunMatchingAction;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ProviderServiceAreaModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderRadiusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
    }

    private function createTestUser(string $role = 'provider'): UserModel
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider ' . Str::random(4),
            'email' => 'provider-' . Str::random(6) . '@test.com',
            'password' => 'password123',
            'status' => 'active',
        ]);
        $user->assignRole($role);
        return $user;
    }

    public function test_updating_radius_km_persists_in_database_and_is_returned_on_get(): void
    {
        $providerUser = $this->createTestUser('provider');
        Sanctum::actingAs($providerUser);

        // Update radius to 20
        $updateResponse = $this->patchJson('/api/v1/provider/profile', [
            'radius_km' => 20,
            'bio' => 'Cerrajería 24hs',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.radius_km', 20);

        // GET profile returns radius_km = 20
        $getResponse = $this->getJson('/api/v1/provider/profile');

        $getResponse->assertStatus(200)
            ->assertJsonPath('data.radius_km', 20);

        $this->assertDatabaseHas('provider_service_areas', [
            'radius_km' => 20,
        ]);
    }

    public function test_matching_respects_provider_radius_km(): void
    {
        $category = CategoryModel::where('slug', 'cerrajeria')->firstOrFail();

        // Client at (-27.4692, -58.8306)
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Client',
            'email' => 'client@test.com',
            'password' => 'password123',
            'status' => 'active',
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Cerrajero urgente',
            'location_lat' => -27.4692,
            'location_lng' => -58.8306,
            'urgency' => 'immediate',
            'status' => 'pending_matching',
        ]);

        // Provider ~20 km away at (-27.6500, -58.8306)
        $providerUser = $this->createTestUser('provider');
        $providerProfile = ProviderProfileModel::factory()->enabled()->create([
            'user_id' => $providerUser->id,
            'base_lat' => -27.6500,
            'base_lng' => -58.8306,
            'availability_status' => 'available',
        ]);

        ProviderCategoryModel::create([
            'provider_id' => $providerProfile->id,
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        // Set radius_km = 5 for provider
        ProviderServiceAreaModel::create([
            'provider_id' => $providerProfile->id,
            'center_lat' => -27.6500,
            'center_lng' => -58.8306,
            'radius_km' => 5,
            'label' => 'Cercano',
        ]);

        $action = new RunMatchingAction();

        // 1. With radius_km = 5, provider at ~20km does NOT appear
        $resultsSmallRadius = $action->execute($serviceRequest);
        $providerIdsSmall = array_map(fn($item) => $item['provider']->id, $resultsSmallRadius);
        $this->assertNotContains($providerProfile->id, $providerIdsSmall);

        // 2. Update radius_km = 25 for provider
        ProviderServiceAreaModel::where('provider_id', $providerProfile->id)->update(['radius_km' => 25]);

        // With radius_km = 25, provider at ~20km DOES appear
        $resultsLargeRadius = $action->execute($serviceRequest);
        $providerIdsLarge = array_map(fn($item) => $item['provider']->id, $resultsLargeRadius);
        $this->assertContains($providerProfile->id, $providerIdsLarge);
    }
}
