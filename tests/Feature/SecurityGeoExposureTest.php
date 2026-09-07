<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Domain\Works\Enums\WorkStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityGeoExposureTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $client;
    protected UserModel $provider1;
    protected UserModel $provider2;
    protected ProviderProfileModel $provider1Profile;
    protected ProviderProfileModel $provider2Profile;
    protected ServiceRequestModel $serviceRequest;

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

        $this->provider1 = UserModel::create([
            'name' => 'Provider 1',
            'email' => 'provider1@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $this->provider1->assignRole('provider');

        $this->provider1Profile = ProviderProfileModel::create([
            'user_id' => $this->provider1->id,
            'professional_title' => 'Plumber',
            'availability_status' => 'available',
        ]);

        $this->provider2 = UserModel::create([
            'name' => 'Provider 2',
            'email' => 'provider2@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $this->provider2->assignRole('provider');

        $this->provider2Profile = ProviderProfileModel::create([
            'user_id' => $this->provider2->id,
            'professional_title' => 'Electrician',
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
    }

    public function test_provider_cannot_view_exact_coords_without_confirmed_work()
    {
        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::PendingDiagnosisQuote->value,
            'confirmed_at' => null,
        ]);

        $this->actingAs($this->provider1);

        $response = $this->getJson("/api/v1/provider/work-requests");

        $this->assertTrue($response['data'][0]['is_approximate']);
        $this->assertNotEquals(-34.6037, $response['data'][0]['location_lat']);
        $this->assertNotEquals(-58.3816, $response['data'][0]['location_lng']);
    }

    public function test_provider_can_view_exact_coords_after_confirmation()
    {
        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($this->provider1);

        $response = $this->getJson("/api/v1/provider/work-requests");

        $this->assertFalse($response['data'][0]['is_approximate']);
        $this->assertEquals(-34.6037, $response['data'][0]['location_lat']);
        $this->assertEquals(-58.3816, $response['data'][0]['location_lng']);
    }

    public function test_location_presenter_blocks_exact_coords_without_confirmation()
    {
        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::PendingDiagnosisQuote->value,
            'confirmed_at' => null,
        ]);

        $locationData = \App\Domain\Location\Services\LocationPresenter::present(
            $this->serviceRequest,
            $this->provider1
        );

        $this->assertTrue($locationData['is_approximate']);
        $this->assertNotEquals(-34.6037, $locationData['location_lat']);
    }

    public function test_unconfirmed_work_with_complete_coords_returns_approximate_zone()
    {
        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider2Profile->id,
            'status' => WorkStatus::PendingDiagnosisQuote->value,
            'confirmed_at' => null,
        ]);

        $location = \App\Domain\Location\Services\LocationPresenter::present($this->serviceRequest, $this->provider2);
        $this->assertTrue($location['is_approximate']);
        $this->assertNotEquals(-34.6037, $location['location_lat']);
        $this->assertNotEquals(-58.3816, $location['location_lng']);
    }

    public function test_confirmed_work_with_complete_coords_returns_exact_location()
    {
        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $location = \App\Domain\Location\Services\LocationPresenter::present($this->serviceRequest, $this->provider1);
        $this->assertFalse($location['is_approximate']);
        $this->assertEquals(-34.6037, $location['location_lat']);
        $this->assertEquals(-58.3816, $location['location_lng']);
    }

    public function test_confirmed_work_with_null_coords_returns_approximate_zone()
    {
        $reqNoCoords = ServiceRequestModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->serviceRequest->category_id,
            'raw_prompt' => 'Solicitud sin coords',
            'location_address' => 'Palermo, CABA',
            'location_lat' => null,
            'location_lng' => null,
            'urgency' => 'scheduled',
            'status' => 'active',
        ]);

        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $reqNoCoords->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $location = \App\Domain\Location\Services\LocationPresenter::present($reqNoCoords, $this->provider1);
        $this->assertTrue($location['is_approximate']);
        $this->assertEquals('Palermo', $location['location_address']);
    }

    public function test_get_work_location_endpoint_respects_disclosure_rules()
    {
        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::PendingDiagnosisQuote->value,
            'confirmed_at' => null,
        ]);

        // 1. Unconfirmed provider gets approximate
        $res = $this->actingAs($this->provider1)->getJson("/api/v1/works/{$work->uuid}/location");
        $res->assertStatus(200);
        $this->assertTrue($res['data']['is_approximate']);
        $this->assertNotEquals(-34.6037, $res['data']['location_lat']);

        // 2. Unauthorized provider gets 403
        $resOther = $this->actingAs($this->provider2)->getJson("/api/v1/works/{$work->uuid}/location");
        $resOther->assertStatus(403);

        // 3. Confirm work -> Provider gets exact
        $work->update([
            'status' => WorkStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);
        $resConfirmed = $this->actingAs($this->provider1)->getJson("/api/v1/works/{$work->uuid}/location");
        $resConfirmed->assertStatus(200);
        $this->assertFalse($resConfirmed['data']['is_approximate']);
        $this->assertEquals(-34.6037, $resConfirmed['data']['location_lat']);
    }

    public function test_adversarial_attempt_to_force_exact_location_via_query_params_fails()
    {
        $work = WorkModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'service_request_id' => $this->serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::PendingDiagnosisQuote->value,
            'confirmed_at' => null,
        ]);

        $res = $this->actingAs($this->provider1)->getJson("/api/v1/works/{$work->uuid}/location?exact=true&reveal=1&force=true&status=confirmed&confirmed_at=" . now()->toISOString());
        $res->assertStatus(200);
        $this->assertTrue($res['data']['is_approximate']);
        $this->assertNotEquals(-34.6037, $res['data']['location_lat']);
    }

    public function test_location_presenter_logs_warning_on_null_coords_fallback()
    {
        \Illuminate\Support\Facades\Log::spy();

        $reqNull = ServiceRequestModel::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->serviceRequest->category_id,
            'raw_prompt' => 'Solicitud sin coords para log test',
            'location_address' => 'Dirección cualquiera',
            'location_lat' => null,
            'location_lng' => null,
            'urgency' => 'scheduled',
            'status' => 'active',
        ]);

        \App\Domain\Location\Services\LocationPresenter::present($reqNull, null);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->atLeast()->once();
    }
}
