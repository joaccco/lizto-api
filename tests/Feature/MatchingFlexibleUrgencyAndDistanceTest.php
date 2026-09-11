<?php

namespace Tests\Feature;

use App\Application\Matching\Actions\RunMatchingAction;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MatchingFlexibleUrgencyAndDistanceTest extends TestCase
{
    use RefreshDatabase;

    private function createMatchingSetup(): array
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Test',
            'email' => 'client_' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $proUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Lucas Electricista',
            'email' => 'pro_' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Electricidad',
            'slug' => 'electricidad_' . Str::random(4),
        ]);

        $providerProfile = ProviderProfileModel::factory()->enabled()->create([
            'user_id' => $proUser->id,
            'base_lat' => -34.6037,
            'base_lng' => -58.3816,
            'availability_status' => 'available',
        ]);

        $providerProfile->categories()->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        return [$client, $proUser, $providerProfile, $category];
    }

    /**
     * Test 1: Un profesional con trabajos agendados en otras fechas sí aparece en una solicitud flexible.
     */
    public function test_provider_with_scheduled_works_on_other_dates_appears_in_flexible_request(): void
    {
        [$client, $proUser, $providerProfile, $category] = $this->createMatchingSetup();

        $srExisting = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'trabajo previo',
            'urgency' => 'scheduled',
            'status' => 'active',
            'is_remote' => true,
        ]);

        // Work scheduled for future date
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srExisting->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-09-25 14:00:00', 'America/Argentina/Buenos_Aires'),
            'estimated_duration_min' => 120,
        ]);

        // Flexible service request without date or window
        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'necesito un electricista',
            'urgency' => 'flexible',
            'scheduled_date' => null,
            'window_start' => null,
            'window_end' => null,
            'status' => 'pending_matching',
            'is_remote' => true,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($sr);

        $this->assertCount(1, $results);
        $this->assertEquals($providerProfile->id, $results[0]['provider']->id);
        $this->assertGreaterThan(0, $results[0]['score_total']);
    }

    /**
     * Test 2: Ese mismo profesional no aparece si la solicitud tiene ventana horaria que se solapa con su trabajo.
     */
    public function test_provider_is_excluded_when_request_schedule_overlaps_with_confirmed_work(): void
    {
        [$client, $proUser, $providerProfile, $category] = $this->createMatchingSetup();

        $srExisting = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'trabajo previo',
            'urgency' => 'scheduled',
            'status' => 'active',
            'is_remote' => true,
        ]);

        // Work scheduled from 14:00 to 16:00
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srExisting->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-09-25 14:00:00', 'America/Argentina/Buenos_Aires'),
            'estimated_duration_min' => 120,
        ]);

        // Scheduled request overlapping that exact window: 2026-09-25 14:00 - 18:00
        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'necesito un electricista en este horario',
            'urgency' => 'scheduled',
            'scheduled_date' => '2026-09-25',
            'window_start' => '14:00',
            'window_end' => '18:00',
            'status' => 'pending_matching',
            'is_remote' => true,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($sr);

        // Excluded due to direct overlap
        $this->assertEmpty($results);
    }

    /**
     * Test 3: Un profesional con domicilio base lejos y área de cobertura en la zona da distancia corta, no la del domicilio.
     */
    public function test_effective_distance_uses_service_area_in_zone_instead_of_distant_base_address(): void
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente CABA',
            'email' => 'client_caba_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $proUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Lucas Corrientes y CABA',
            'email' => 'pro_corrientes_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Electricidad',
            'slug' => 'electricidad_caba_' . Str::random(4),
        ]);

        // Provider base location in Corrientes (~800km from CABA)
        $providerProfile = ProviderProfileModel::factory()->enabled()->create([
            'user_id' => $proUser->id,
            'base_lat' => -27.4810,
            'base_lng' => -58.8190,
            'base_address' => 'Corrientes Centro',
            'availability_status' => 'available',
        ]);

        $providerProfile->categories()->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        // Secondary service area in CABA (Palermo / Recoleta coverage)
        $providerProfile->serviceAreas()->create([
            'center_lat' => -34.5889,
            'center_lng' => -58.4306,
            'radius_km' => 50,
            'label' => 'CABA',
        ]);

        // Request in Palermo, CABA (-34.5889, -58.4306)
        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'necesito electricista en Palermo',
            'urgency' => 'flexible',
            'status' => 'pending_matching',
            'location_lat' => -34.5889,
            'location_lng' => -58.4306,
            'location_address' => 'Thames 1842, Palermo, CABA',
            'is_remote' => false,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($sr);

        $this->assertNotEmpty($results);

        $card = $results[0];
        $distanceKm = $card['snapshot']['distance_km'];

        // Distance must be short (< 1 km), not ~800 km from Corrientes!
        $this->assertNotNull($distanceKm);
        $this->assertLessThan(5.0, $distanceKm);

        // Distance score must be high (> 0.8), not 0.0
        $this->assertGreaterThan(0.8, $card['score_breakdown']['distance']);
    }
}
