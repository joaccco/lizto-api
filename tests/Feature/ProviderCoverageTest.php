<?php

namespace Tests\Feature;

use App\Application\Matching\Actions\RunMatchingAction;
use App\Domain\ServiceRequests\Enums\RequestStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createSetup(): array
    {
        $client = UserModel::create(['name' => 'Cliente Cobertura', 'email' => 'client_cov_' . Str::random(5) . '@test.com', 'password' => bcrypt('password')]);
        $providerUser1 = UserModel::create(['name' => 'Pro Cov 1', 'email' => 'pro_cov1_' . Str::random(5) . '@test.com', 'password' => bcrypt('password')]);
        $providerUser2 = UserModel::create(['name' => 'Pro Cov 2', 'email' => 'pro_cov2_' . Str::random(5) . '@test.com', 'password' => bcrypt('password')]);
        $category = CategoryModel::first();

        $provider1 = ProviderProfileModel::factory()->enabled()->create([
            'user_id' => $providerUser1->id,
            'bio' => 'Pro1',
            'base_lat' => -27.4692,
            'base_lng' => -58.8306,
            'availability_status' => 'available',
        ]);
        $provider1->categories()->create(['category_id' => $category->id]);
        $provider1->serviceAreas()->create(['center_lat' => -27.4692, 'center_lng' => -58.8306, 'radius_km' => 15, 'label' => 'Base']);

        $provider2 = ProviderProfileModel::factory()->enabled()->create([
            'user_id' => $providerUser2->id,
            'bio' => 'Pro2',
            'base_lat' => -27.4692,
            'base_lng' => -58.8306,
            'availability_status' => 'available',
        ]);
        $provider2->categories()->create(['category_id' => $category->id]);

        return [$client, $providerUser1, $provider1, $providerUser2, $provider2, $category];
    }

    /** 1. El profesional modifica su radio y el cambio persiste. */
    public function test_provider_updates_coverage_radius_successfully(): void
    {
        [$client, $providerUser1, $provider1] = $this->createSetup();

        $res = $this->actingAs($providerUser1, 'sanctum')->postJson('/api/v1/provider/profile', [
            'radius_km' => 25,
        ]);

        $res->assertStatus(200);
        $this->assertEquals(25, $res->json('data.radius_km'));
        $this->assertEquals(25, $provider1->fresh()->serviceAreas()->first()->radius_km);
    }

    /** 2. Un radio mayor al máximo (50 km) es rechazado. */
    public function test_radius_greater_than_max_is_rejected(): void
    {
        [$client, $providerUser1, $provider1] = $this->createSetup();

        $res = $this->actingAs($providerUser1, 'sanctum')->postJson('/api/v1/provider/profile', [
            'radius_km' => 51,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['radius_km']);
    }

    /** 3. Un radio menor al mínimo (1 km) es rechazado. */
    public function test_radius_less_than_min_is_rejected(): void
    {
        [$client, $providerUser1, $provider1] = $this->createSetup();

        $res = $this->actingAs($providerUser1, 'sanctum')->postJson('/api/v1/provider/profile', [
            'radius_km' => 0,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['radius_km']);
    }

    /** 4. Reducir el radio no cancela ni modifica trabajos ya confirmados que quedan fuera del área nueva. */
    public function test_reducing_radius_does_not_cancel_or_modify_confirmed_works(): void
    {
        [$client, $providerUser1, $provider1, $providerUser2, $provider2, $category] = $this->createSetup();

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $category->id, 'raw_prompt' => 'Trabajo distante']);
        $confirmedWork = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $provider1->id,
            'status' => 'confirmed',
            'work_address' => 'A 40km de distancia',
        ]);

        $res = $this->actingAs($providerUser1, 'sanctum')->postJson('/api/v1/provider/profile', [
            'radius_km' => 5,
        ]);
        $res->assertStatus(200);

        $this->assertEquals('confirmed', $confirmedWork->fresh()->status->value);
    }

    /** 5. El motor de matching respeta el radio nuevo en las búsquedas siguientes. */
    public function test_matching_engine_respects_updated_radius(): void
    {
        [$client, $providerUser1, $provider1, $providerUser2, $provider2, $category] = $this->createSetup();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Servicio a 30km',
            'location_lat' => -27.7000,
            'location_lng' => -58.8306,
            'status' => RequestStatus::PendingMatching,
            'is_remote' => false,
        ]);

        $matcher = app(RunMatchingAction::class);

        $results = $matcher->execute($sr);
        $matchedProviderIds = array_map(fn($r) => $r['provider']->id, $results);
        $this->assertNotContains($provider1->id, $matchedProviderIds);

        $this->actingAs($providerUser1, 'sanctum')->postJson('/api/v1/provider/profile', [
            'radius_km' => 40,
        ]);

        $results2 = $matcher->execute($sr);
        $matchedProviderIds2 = array_map(fn($r) => $r['provider']->id, $results2);
        $this->assertContains($provider1->id, $matchedProviderIds2);
    }
}
