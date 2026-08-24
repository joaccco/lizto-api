<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderScheduleFormatTest extends TestCase
{
    use RefreshDatabase;

    private function createProviderSetup(): array
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Test',
            'email' => 'provider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('provider');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        $category = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cerrajería',
            'slug' => 'cerrajeria',
        ]);

        $profile->categories()->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Test',
            'email' => 'client_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        return [$user, $profile, $category, $client];
    }

    private function createServiceRequestWithMatch($client, $category, $profile, array $srData): ServiceRequestModel
    {
        $sr = ServiceRequestModel::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Solicitud de prueba',
            'status' => 'pending_matching',
            'created_at' => Carbon::now()->subDays(3),
        ], $srData));

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'status' => 'active',
        ]);

        MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $profile->id,
            'rank_position' => 1,
            'score_total' => 0.95,
            'card_status' => 'accepted',
        ]);

        return $sr;
    }

    /**
     * Test 1: Una solicitud con urgencia inmediata devuelve la etiqueta de atención inmediata y no expone fecha ni franja.
     */
    public function test_immediate_urgency_request_returns_immediate_label_without_date_or_window(): void
    {
        [$user, $profile, $category, $client] = $this->createProviderSetup();

        $this->createServiceRequestWithMatch($client, $category, $profile, [
            'urgency' => 'immediate',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $response->assertStatus(200);

        $data = $response->json('data.0');
        $this->assertNotNull($data);
        $this->assertArrayHasKey('schedule', $data);

        $schedule = $data['schedule'];
        $this->assertStringContainsString('Atención inmediata', $schedule['label']);
        $this->assertNull($schedule['scheduled_date']);
        $this->assertNull($schedule['window_start']);
        $this->assertNull($schedule['window_end']);
    }

    /**
     * Test 2: Una solicitud con urgencia para hoy y franja definida devuelve la franja correcta.
     */
    public function test_today_urgency_with_time_window_returns_correct_range(): void
    {
        [$user, $profile, $category, $client] = $this->createProviderSetup();

        $this->createServiceRequestWithMatch($client, $category, $profile, [
            'urgency' => 'today',
            'scheduled_date' => Carbon::today()->format('Y-m-d'),
            'window_start' => '14:00',
            'window_end' => '18:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $response->assertStatus(200);

        $schedule = $response->json('data.0.schedule');
        $this->assertNotNull($schedule);
        $this->assertEquals('14:00', $schedule['window_start']);
        $this->assertEquals('18:00', $schedule['window_end']);
        $this->assertStringContainsString('Hoy', $schedule['label']);
        $this->assertStringContainsString('14:00', $schedule['label']);
        $this->assertStringContainsString('18:00', $schedule['label']);
    }

    /**
     * Test 3: Una solicitud agendada devuelve fecha y franja correctas.
     */
    public function test_scheduled_urgency_returns_formatted_date_and_time_range(): void
    {
        [$user, $profile, $category, $client] = $this->createProviderSetup();

        $futureDate = Carbon::now()->addDays(5);
        $this->createServiceRequestWithMatch($client, $category, $profile, [
            'urgency' => 'scheduled',
            'preferred_datetime' => $futureDate,
            'window_start' => '10:00',
            'window_end' => '12:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $response->assertStatus(200);

        $schedule = $response->json('data.0.schedule');
        $this->assertNotNull($schedule);
        $this->assertEquals($futureDate->format('Y-m-d'), $schedule['scheduled_date']);
        $this->assertEquals('10:00', $schedule['window_start']);
        $this->assertEquals('12:00', $schedule['window_end']);
        $this->assertNotEmpty($schedule['label']);
        $this->assertStringContainsString((string) $futureDate->day, $schedule['label']);
    }

    /**
     * Test 4: Una solicitud sin datos de agenda devuelve la etiqueta de a coordinar, y en ningún caso la fecha de creación.
     */
    public function test_request_without_schedule_data_returns_to_coordinate_label_and_never_created_at(): void
    {
        [$user, $profile, $category, $client] = $this->createProviderSetup();

        $oldDate = Carbon::now()->subDays(10);
        $this->createServiceRequestWithMatch($client, $category, $profile, [
            'urgency' => 'scheduled',
            'preferred_datetime' => null,
            'scheduled_date' => null,
            'window_start' => null,
            'window_end' => null,
            'created_at' => $oldDate,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $response->assertStatus(200);

        $schedule = $response->json('data.0.schedule');
        $this->assertNotNull($schedule);
        $this->assertEquals('A coordinar', $schedule['label']);
        $this->assertNull($schedule['scheduled_date']);
        $this->assertFalse(str_contains($schedule['label'], $oldDate->format('d/m/Y')));
    }

    /**
     * Test 5: Los campos preexistentes de la respuesta siguen presentes y sin cambios.
     */
    public function test_preexisting_fields_are_preserved_without_changes(): void
    {
        [$user, $profile, $category, $client] = $this->createProviderSetup();

        $sr = $this->createServiceRequestWithMatch($client, $category, $profile, [
            'urgency' => 'immediate',
            'raw_prompt' => 'Apertura de puerta sin romper',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $response->assertStatus(200);

        $data = $response->json('data.0');
        $this->assertEquals($sr->uuid, $data['id']);
        $this->assertEquals('Cerrajería', $data['category']);
        $this->assertEquals('cerrajeria', $data['category_slug']);
        $this->assertEquals('Apertura de puerta sin romper', $data['raw_prompt']);
        $this->assertEquals('Cliente', $data['client_name']);
        $this->assertEquals('immediate', $data['urgency']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('schedule', $data);
    }
}
