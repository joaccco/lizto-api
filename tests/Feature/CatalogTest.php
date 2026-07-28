<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ProviderSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(ProviderSeeder::class);
    }

    public function test_can_list_categories_with_children(): void
    {
        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'icon', 'children']
                ]
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        // Verify parent category cerrajeria has children
        $cerrajeria = collect($data)->firstWhere('slug', 'cerrajeria');
        $this->assertNotNull($cerrajeria);
        $this->assertNotEmpty($cerrajeria['children']);
    }

    public function test_can_list_providers_with_filters(): void
    {
        $response = $this->getJson('/api/v1/providers?category=cerrajeria&availability=available&lat=-27.4692&lng=-58.8306');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid', 'name', 'avatar_url', 'bio', 'years_experience',
                        'is_verified', 'avg_rating', 'total_reviews',
                        'availability_status', 'distance_km', 'categories'
                    ]
                ],
                'meta' => ['current_page', 'total']
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('available', $data[0]['availability_status']);
    }

    public function test_can_get_provider_detail_by_uuid(): void
    {
        $providerProfile = ProviderProfileModel::first();
        $uuid = $providerProfile->user->uuid;

        $response = $this->getJson("/api/v1/providers/{$uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonStructure([
                'data' => [
                    'uuid', 'name', 'email', 'bio', 'years_experience',
                    'is_verified', 'location', 'availability',
                    'reputation_stats', 'categories', 'service_areas', 'schedules'
                ]
            ]);
    }
}
