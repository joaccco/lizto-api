<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\ServiceRequests\Enums\RequestStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderDashboardFabricatedDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_work_requests_does_not_fabricate_category_or_duration(): void
    {
        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Real',
            'email' => 'pro@test.com',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
            'is_verified' => true,
        ]);

        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Sin Categoria',
            'email' => 'client@test.com',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        // Service request without category
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => null,
            'raw_prompt' => 'Necesito ayuda urgente',
            'status' => RequestStatus::PendingMatching->value,
            'urgency' => 'immediate',
        ]);

        // Work linked without duration specified
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $profile->id,
            'client_id' => $client->id,
            'status' => WorkStatus::PendingConfirmation,
            'estimated_duration_min' => null,
        ]);

        $response = $this->actingAs($providerUser, 'sanctum')
            ->getJson('/api/v1/provider/work-requests');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $item = collect($data)->firstWhere('id', $serviceRequest->uuid);
        $this->assertNotNull($item);
        $this->assertNull($item['category'], 'Category should be null when not specified, not "Servicio general"');
        $this->assertNull($item['category_slug'], 'Category slug should be null when not specified');
        $this->assertNull($item['estimated_duration_min'], 'Estimated duration should be null when not specified, not 60');
    }

    public function test_dashboard_agenda_does_not_fabricate_category_or_duration(): void
    {
        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Agenda',
            'email' => 'proagenda@test.com',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
            'is_verified' => true,
        ]);

        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Agenda',
            'email' => 'clientagenda@test.com',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        // Service request without category
        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => null,
            'raw_prompt' => 'Trabajo agendado sin categoria',
            'status' => RequestStatus::Active->value,
            'urgency' => 'flexible',
        ]);

        // Work without duration agreed
        $workScheduled = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'provider_id' => $profile->id,
            'client_id' => $client->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => now()->addDays(2),
            'estimated_duration_min' => null,
        ]);

        $workUnscheduled = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'provider_id' => $profile->id,
            'client_id' => $client->id,
            'status' => WorkStatus::PendingConfirmation,
            'scheduled_at' => null,
            'estimated_duration_min' => null,
        ]);

        $response = $this->actingAs($providerUser, 'sanctum')
            ->getJson('/api/v1/provider/agenda');

        $response->assertStatus(200);

        $scheduledItem = collect($response->json('data'))->firstWhere('id', $workScheduled->uuid);
        $this->assertNotNull($scheduledItem);
        $this->assertNull($scheduledItem['category'], 'Scheduled work category should be null when not specified');
        $this->assertNull($scheduledItem['estimated_duration_min'], 'Scheduled work duration should be null when not agreed');

        $unscheduledItem = collect($response->json('pending_schedule'))->firstWhere('id', $workUnscheduled->uuid);
        $this->assertNotNull($unscheduledItem);
        $this->assertNull($unscheduledItem['category'], 'Pending work category should be null when not specified');
        $this->assertNull($unscheduledItem['estimated_duration_min'], 'Pending work duration should be null when not agreed');
    }
}
