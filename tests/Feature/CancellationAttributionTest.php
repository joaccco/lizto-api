<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkEventModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Models\ProfessionalMVU;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CancellationAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createWorkSetup(): array
    {
        $category = CategoryModel::first();

        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Test',
            'email' => 'client_' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $proUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Profesional Test',
            'email' => 'pro_' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $proUser->assignRole('provider');

        $proProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $proUser->id,
            'category_id' => $category->id,
            'bio' => 'Profesional verificado',
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'cancellation_count' => 0,
            'coverage_radius_km' => 20,
        ]);

        ProfessionalMVU::create([
            'provider_id' => $proProfile->id,
            'overall_verification_status' => 'approved',
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de cerradura',
            'status' => 'provider_selected',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'client_id' => $client->id,
            'provider_id' => $proProfile->id,
            'status' => WorkStatus::Confirmed,
        ]);

        return [$client, $proUser, $proProfile, $work];
    }

    /**
     * T4: Cancela el cliente y el contador no se mueve.
     */
    public function test_client_cancelling_work_does_not_increment_provider_cancellation_count(): void
    {
        [$client, $proUser, $proProfile, $work] = $this->createWorkSetup();

        $this->assertEquals(0, $proProfile->cancellation_count);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/cancel", [
                'reason' => 'Cliente tuvo un imprevisto',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'cancelled');
        $response->assertJsonPath('data.cancelled_by', 'client');

        $proProfile->refresh();
        $this->assertEquals(0, $proProfile->cancellation_count, 'El contador no debe incrementarse cuando cancela el cliente');

        $this->assertDatabaseHas('work_events', [
            'work_id' => $work->id,
            'event_type' => 'cancelled',
            'actor_id' => $client->id,
        ]);

        $event = WorkEventModel::where('work_id', $work->id)->where('event_type', 'cancelled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('client', $event->metadata['cancelled_by']);
    }

    /**
     * T4: Cancela el profesional y sí se incrementa.
     */
    public function test_provider_cancelling_work_increments_provider_cancellation_count(): void
    {
        [$client, $proUser, $proProfile, $work] = $this->createWorkSetup();

        $this->assertEquals(0, $proProfile->cancellation_count);

        $response = $this->actingAs($proUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/cancel", [
                'reason' => 'No puedo asistir por problema personal',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'cancelled');
        $response->assertJsonPath('data.cancelled_by', 'provider');

        $proProfile->refresh();
        $this->assertEquals(1, $proProfile->cancellation_count, 'El contador debe incrementarse cuando cancela el profesional');

        $this->assertDatabaseHas('work_events', [
            'work_id' => $work->id,
            'event_type' => 'cancelled',
            'actor_id' => $proUser->id,
        ]);

        $event = WorkEventModel::where('work_id', $work->id)->where('event_type', 'cancelled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('provider', $event->metadata['cancelled_by']);
    }
}
