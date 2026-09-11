<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ProviderServiceAreaModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\ProfessionalMVU;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D-05 — Rating Honesty: verifies that a provider with zero reviews
 * never gets a fabricated avg_rating, and never earns "Top Profesional" badge.
 */
class RatingHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_provider_with_no_reviews_gets_null_rating_and_no_top_badge(): void
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'New Provider',
            'email' => 'new_provider_rating@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $user->assignRole('provider');

        $category = CategoryModel::firstOrCreate(
            ['slug' => 'cerrajeria'],
            ['name' => 'Cerrajería', 'icon' => '🔑']
        );

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'availability_status' => 'available',
            'base_lat' => -27.4692,
            'base_lng' => -58.8306,
            'base_address' => 'Corrientes',
            'avg_rating' => 0,          // DB default
            'total_reviews' => 0,       // No reviews
            'total_jobs_completed' => 0,
        ]);

        // MVU approved so provider appears in public catalog
        ProfessionalMVU::create([
            'provider_id' => $profile->id,
            'overall_verification_status' => 'approved',
        ]);

        ProviderCategoryModel::create([
            'provider_id' => $profile->id,
            'category_id' => $category->id,
            'specialties' => ['apertura'],
            'price_type' => 'fixed',
            'is_active' => true,
        ]);

        ProviderServiceAreaModel::create([
            'provider_id' => $profile->id,
            'label' => 'Corrientes',
            'center_lat' => -27.4692,
            'center_lng' => -58.8306,
            'radius_km' => 20,
        ]);

        // Fetch public catalog
        $response = $this->getJson('/api/v1/providers');
        $response->assertStatus(200);

        $providers = collect($response->json('data'));
        $ourProvider = $providers->firstWhere('uuid', $user->uuid)
            ?? $providers->firstWhere('id', $profile->uuid);

        $this->assertNotNull($ourProvider, 'Provider should appear in catalog (MVU approved).');

        // Key assertions: avg_rating must be null, not 5.0 or 4.9 or 0
        $this->assertNull($ourProvider['avg_rating'], 'avg_rating must be null when total_reviews is 0.');
        $this->assertNull(
            $ourProvider['reputation_stats']['avg_rating'],
            'reputation_stats.avg_rating must be null when total_reviews is 0.'
        );

        // No "Top Profesional" badge
        $this->assertNotContains(
            'Top Profesional',
            $ourProvider['badges'],
            'Provider without reviews must not have Top Profesional badge.'
        );
    }
}
