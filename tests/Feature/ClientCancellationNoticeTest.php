<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientCancellationNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    public function test_client_receives_cancelled_status_when_provider_cancels_work(): void
    {
        $client = UserModel::create([
            'name' => 'Cliente Notificacion',
            'email' => 'client_notif_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'name' => 'Roberto Cancela',
            'email' => 'roberto_cancel_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::where('slug', 'plomeria')->first();

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $providerUser->id,
            'category_id' => $category->id,
            'uuid' => (string) Str::uuid(),
            'bio' => 'Plomero',
            'is_verified' => true,
            'coverage_radius_km' => 15,
        ]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $providerProfile->id,
            'overall_verification_status' => 'approved',
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de caño',
            'status' => 'pending_matching',
        ]);

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'shown',
        ]);

        // 1. Confirm work
        $resConfirm = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm", [
                'estimated_duration_min' => 30,
            ]);

        $resConfirm->assertStatus(200);
        $workUuid = $resConfirm->json('data.work_id');

        // 2. Client checks active request -> returns confirmed
        $resClient1 = $this->actingAs($client, 'sanctum')
            ->getJson('/api/v1/requests?status=active&limit=1');

        $resClient1->assertStatus(200)
            ->assertJsonPath('data.0.status', 'confirmed');

        // 3. Provider cancels work
        $resCancel = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$workUuid}/cancel", [
                'reason' => 'Emergencia personal',
            ]);

        $resCancel->assertStatus(200);

        // 4. Client polls active requests -> returns cancelled status
        $resClient2 = $this->actingAs($client, 'sanctum')
            ->getJson('/api/v1/requests?status=active&limit=1');

        $resClient2->assertStatus(200)
            ->assertJsonPath('data.0.status', 'cancelled');
    }
}
