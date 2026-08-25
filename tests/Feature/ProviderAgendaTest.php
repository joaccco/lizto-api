<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderAgendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createSetup(): array
    {
        $client = UserModel::create(['name' => 'Cliente Agenda', 'email' => 'client_ag_' . Str::random(5) . '@test.com', 'password' => bcrypt('password')]);
        $providerUser = UserModel::create(['name' => 'Pro Agenda', 'email' => 'pro_ag_' . Str::random(5) . '@test.com', 'password' => bcrypt('password')]);
        $category = CategoryModel::first();

        $provider = ProviderProfileModel::create([
            'user_id' => $providerUser->id, 'category_id' => $category->id, 'uuid' => (string) Str::uuid(), 'bio' => 'Pro Agenda', 'is_verified' => true,
        ]);

        return [$client, $providerUser, $provider, $category];
    }

    /** B.1: Un trabajo sin horario agendado no aparece en el calendario y sí aparece en pendientes de coordinar. */
    public function test_work_without_scheduled_time_is_returned_as_pending_schedule_not_in_calendar(): void
    {
        [$client, $providerUser, $provider, $category] = $this->createSetup();

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $category->id, 'raw_prompt' => 'Sin horario']);
        $unscheduledWork = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'status' => 'confirmed',
            'scheduled_at' => null,
        ]);

        $res = $this->actingAs($providerUser, 'sanctum')->getJson('/api/v1/provider/agenda');
        $res->assertStatus(200);

        $calendarEvents = $res->json('data');
        $this->assertEmpty($calendarEvents);

        $pending = $res->json('pending_schedule');
        $this->assertCount(1, $pending);
        $this->assertEquals($unscheduledWork->uuid, $pending[0]['work_id']);
        $this->assertNull($pending[0]['scheduled_at']);
    }

    /** B.2: Un trabajo con horario agendado sigue apareciendo en su fecha correcta. */
    public function test_work_with_scheduled_time_appears_on_correct_date(): void
    {
        [$client, $providerUser, $provider, $category] = $this->createSetup();

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $category->id, 'raw_prompt' => 'Con horario']);
        $scheduledTime = now()->addDays(2)->setHour(14)->setMinute(30)->setSecond(0);

        $scheduledWork = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'status' => 'confirmed',
            'scheduled_at' => $scheduledTime,
        ]);

        $res = $this->actingAs($providerUser, 'sanctum')->getJson('/api/v1/provider/agenda');
        $res->assertStatus(200);

        $calendarEvents = $res->json('data');
        $this->assertCount(1, $calendarEvents);
        $this->assertEquals($scheduledWork->uuid, $calendarEvents[0]['work_id']);
        $this->assertEquals((int) $scheduledTime->format('j'), $calendarEvents[0]['day']);
        $this->assertEquals((int) $scheduledTime->format('n'), $calendarEvents[0]['month']);
    }

    /** B.3: Los trabajos completados y cancelados están presentes en la respuesta de la agenda. */
    public function test_completed_and_cancelled_works_are_present_in_agenda_response(): void
    {
        [$client, $providerUser, $provider, $category] = $this->createSetup();

        $sr1 = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $category->id, 'raw_prompt' => 'Completado']);
        $completedWork = WorkModel::create([
            'uuid' => (string) Str::uuid(), 'service_request_id' => $sr1->id, 'client_id' => $client->id, 'provider_id' => $provider->id,
            'status' => 'completed', 'scheduled_at' => now()->subDays(1),
        ]);

        $sr2 = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $category->id, 'raw_prompt' => 'Cancelado']);
        $cancelledWork = WorkModel::create([
            'uuid' => (string) Str::uuid(), 'service_request_id' => $sr2->id, 'client_id' => $client->id, 'provider_id' => $provider->id,
            'status' => 'cancelled', 'scheduled_at' => now()->subDays(2),
        ]);

        $res = $this->actingAs($providerUser, 'sanctum')->getJson('/api/v1/provider/agenda');
        $res->assertStatus(200);

        $events = $res->json('data');
        $statuses = array_column($events, 'status');
        $this->assertContains('completed', $statuses);
        $this->assertContains('cancelled', $statuses);
    }
}
