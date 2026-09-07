<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Infrastructure\Persistence\Eloquent\WorkQuoteModel;
use App\Domain\Works\Enums\WorkStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityFakeDataTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $client;
    protected UserModel $provider;
    protected ProviderProfileModel $providerProfile;
    protected ServiceRequestModel $serviceRequest;
    protected WorkModel $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\CategorySeeder::class);

        $this->client = UserModel::create([
            'name' => 'Cliente Test',
            'email' => 'client@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $this->client->assignRole('client');

        $this->provider = UserModel::create([
            'name' => 'Provider Test',
            'email' => 'provider@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $this->provider->assignRole('provider');

        $this->providerProfile = ProviderProfileModel::create([
            'user_id' => $this->provider->id,
            'professional_title' => 'Plumber',
            'availability_status' => 'available',
        ]);

        $category = \App\Infrastructure\Persistence\Eloquent\CategoryModel::first();

        $this->serviceRequest = ServiceRequestModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Need plumbing service',
            'location_lat' => -34.6037,
            'location_lng' => -58.3816,
            'location_address' => 'Avenida Corrientes 123, Buenos Aires',
            'urgency' => 'scheduled',
            'status' => 'active',
        ]);

        $this->work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::PendingDiagnosisQuote->value,
            'work_lat' => -34.6037,
            'work_lng' => -58.3816,
            'work_address' => 'Avenida Corrientes 123',
            'estimated_duration_min' => 60,
        ]);

        WorkQuoteModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'work_id' => $this->work->id,
            'provider_id' => $this->providerProfile->id,
            'client_id' => $this->client->id,
            'amount' => 5000,
            'currency' => 'ARS',
            'terms_conditions' => 'Test quote',
            'origin' => 'provider_quote',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    public function test_cannot_complete_work_without_duration()
    {
        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::PendingDiagnosisQuote->value,
            'work_lat' => -34.6037,
            'work_lng' => -58.3816,
            'work_address' => 'Address',
            'estimated_duration_min' => null,
        ]);

        WorkQuoteModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $this->providerProfile->id,
            'client_id' => $this->client->id,
            'amount' => 5000,
            'currency' => 'ARS',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $this->actingAs($this->provider);

        $response = $this->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'No se puede completar el trabajo sin una duración estimada definida.',
        ]);
    }

    public function test_cannot_complete_work_without_location_coords()
    {
        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::PendingDiagnosisQuote->value,
            'work_lat' => null,
            'work_lng' => null,
            'work_address' => 'Address',
            'estimated_duration_min' => 60,
        ]);

        WorkQuoteModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $this->providerProfile->id,
            'client_id' => $this->client->id,
            'amount' => 5000,
            'currency' => 'ARS',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $this->actingAs($this->provider);

        $response = $this->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'No se puede completar el trabajo sin información de ubicación válida.',
        ]);
    }

    public function test_can_complete_valid_work()
    {
        $this->actingAs($this->provider);

        $response = $this->postJson("/api/v1/works/{$this->work->uuid}/complete");

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'El trabajo fue marcado como completado.',
        ]);
    }

    public function test_seeder_fake_works_are_excluded_from_api_lists()
    {
        $fakeWork = WorkModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::Confirmed->value,
            'fake_data_source' => 'test',
        ]);

        $this->actingAs($this->provider);
        $response = $this->getJson('/api/v1/provider/work-requests');

        $response->assertStatus(200);
        $workIds = collect($response->json('data'))->pluck('work_id')->filter()->toArray();
        $this->assertNotContains($fakeWork->uuid, $workIds);
    }

    public function test_real_works_with_null_fake_data_source_are_returned_normally()
    {
        $realReq = ServiceRequestModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->serviceRequest->category_id,
            'raw_prompt' => 'Real work prompt',
            'location_lat' => -34.6037,
            'location_lng' => -58.3816,
            'location_address' => 'Avenida Corrientes 123, Buenos Aires',
            'urgency' => 'scheduled',
            'status' => 'active',
        ]);

        $realWork = WorkModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'service_request_id' => $realReq->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::Confirmed->value,
            'fake_data_source' => null,
        ]);

        $this->actingAs($this->provider);
        $response = $this->getJson('/api/v1/provider/work-requests');

        $response->assertStatus(200);
        $workIds = collect($response->json('data'))->pluck('work_id')->filter()->toArray();
        $this->assertContains($realWork->uuid, $workIds);
    }

    public function test_direct_access_or_patch_to_fake_work_uuid_returns_404()
    {
        $fakeWork = WorkModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::Confirmed->value,
            'fake_data_source' => 'test',
        ]);

        $this->actingAs($this->provider);

        // 1. PATCH returns 404
        $patchRes = $this->patchJson("/api/v1/works/{$fakeWork->uuid}", [
            'estimated_duration_min' => 120,
        ]);
        $patchRes->assertStatus(404);

        // 2. GET location returns 404
        $locRes = $this->getJson("/api/v1/works/{$fakeWork->uuid}/location");
        $locRes->assertStatus(404);
    }

    public function test_adversarial_query_parameter_injection_cannot_expose_fake_data()
    {
        $fakeWork = WorkModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::Confirmed->value,
            'fake_data_source' => 'test',
        ]);

        $this->actingAs($this->provider);

        // Attempting to bypass global scope via malicious query parameters
        $res = $this->patchJson("/api/v1/works/{$fakeWork->uuid}?with_fake=1&fake_data_source=test&without_scopes=1", [
            'estimated_duration_min' => 90,
        ]);
        $res->assertStatus(404);
    }

    public function test_seeder_fake_work_creation_tracks_data_source()
    {
        $fakeWork = WorkModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::Confirmed->value,
            'fake_data_source' => 'test_seeder',
        ]);

        $foundWithoutScope = WorkModel::withFakeData()->where('uuid', $fakeWork->uuid)->first();
        $this->assertNotNull($foundWithoutScope);
        $this->assertEquals('test_seeder', $foundWithoutScope->fake_data_source);

        $foundWithScope = WorkModel::where('uuid', $fakeWork->uuid)->first();
        $this->assertNull($foundWithScope);
    }
}
