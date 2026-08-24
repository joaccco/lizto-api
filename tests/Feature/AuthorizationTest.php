<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Infrastructure\Persistence\Eloquent\WorkQuoteModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
    }

    private function createTestUser(string $role = 'client'): UserModel
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'User ' . Str::random(4),
            'email' => 'test-' . Str::random(6) . '@test.com',
            'password' => 'password123',
            'status' => 'active',
        ]);
        $user->assignRole($role);
        return $user;
    }

    public function test_client_a_cannot_cancel_client_b_request_returns_403(): void
    {
        $clientA = $this->createTestUser('client');
        $clientB = $this->createTestUser('client');

        $requestB = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $clientB->id,
            'raw_prompt' => 'Solicitud de B',
            'status' => 'pending_survey',
        ]);

        Sanctum::actingAs($clientA);

        $response = $this->postJson("/api/v1/requests/{$requestB->uuid}/cancel");

        $response->assertStatus(403)
            ->assertJson(['message' => 'No tenés permiso para realizar esta acción.']);
    }

    public function test_client_a_cannot_rate_client_b_work_returns_403(): void
    {
        $clientA = $this->createTestUser('client');
        $clientB = $this->createTestUser('client');
        $providerUser = $this->createTestUser('provider');

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $providerUser->id,
            'bio' => 'Provider',
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $clientB->id,
            'raw_prompt' => 'Solicitud de B',
            'status' => 'completed',
        ]);

        $matchSession = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'completed',
        ]);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $matchSession->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 1.0,
            'card_status' => 'accepted',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'match_card_id' => $matchCard->id,
            'client_id' => $clientB->id,
            'provider_id' => $providerProfile->id,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($clientA);

        $response = $this->postJson("/api/v1/works/{$work->uuid}/rate", [
            'score' => 5,
            'comment' => 'Intento invalido',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'No tenés permiso para realizar esta acción.']);
    }

    public function test_unassigned_provider_cannot_complete_work_returns_403(): void
    {
        $client = $this->createTestUser('client');
        $providerAssigned = $this->createTestUser('provider');
        $providerUnassigned = $this->createTestUser('provider');

        $profileAssigned = ProviderProfileModel::create([
            'user_id' => $providerAssigned->id,
            'bio' => 'Assigned',
        ]);

        ProviderProfileModel::create([
            'user_id' => $providerUnassigned->id,
            'bio' => 'Unassigned',
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'raw_prompt' => 'Solicitud',
            'status' => 'active',
        ]);

        $matchSession = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $matchSession->id,
            'provider_id' => $profileAssigned->id,
            'rank_position' => 1,
            'score_total' => 1.0,
            'card_status' => 'accepted',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'match_card_id' => $matchCard->id,
            'client_id' => $client->id,
            'provider_id' => $profileAssigned->id,
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($providerUnassigned);

        $response = $this->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(403)
            ->assertJson(['message' => 'No tenés permiso para realizar esta acción.']);
    }

    public function test_legitimate_owner_can_cancel_complete_rate_returns_200(): void
    {
        $client = $this->createTestUser('client');
        $providerUser = $this->createTestUser('provider');

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $providerUser->id,
            'bio' => 'Provider',
        ]);

        // Client can cancel own request
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'raw_prompt' => 'Solicitud propia',
            'status' => 'pending_survey',
        ]);

        Sanctum::actingAs($client);
        $cancelResponse = $this->postJson("/api/v1/requests/{$serviceRequest->uuid}/cancel");
        $cancelResponse->assertStatus(200);

        // Assigned provider can complete work
        $serviceRequest2 = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'raw_prompt' => 'Solicitud 2',
            'status' => 'active',
        ]);

        $matchSession = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest2->id,
            'status' => 'active',
        ]);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $matchSession->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 1.0,
            'card_status' => 'accepted',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest2->id,
            'match_card_id' => $matchCard->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => 'in_progress',
        ]);

        $quote = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 15000,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        $work->applyAcceptedQuote($quote);

        Sanctum::actingAs($providerUser);
        $completeResponse = $this->postJson("/api/v1/works/{$work->uuid}/complete");
        $completeResponse->assertStatus(200);

        // Client owner can rate completed work
        Sanctum::actingAs($client);
        $rateResponse = $this->postJson("/api/v1/works/{$work->uuid}/rate", [
            'score' => 5,
            'comment' => 'Excelente servicio',
        ]);
        $rateResponse->assertStatus(200);
    }

    public function test_cannot_rate_same_work_twice_returns_422(): void
    {
        $client = $this->createTestUser('client');
        $providerUser = $this->createTestUser('provider');

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $providerUser->id,
            'bio' => 'Provider',
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'raw_prompt' => 'Solicitud',
            'status' => 'completed',
        ]);

        $matchSession = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'completed',
        ]);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $matchSession->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 1.0,
            'card_status' => 'accepted',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'match_card_id' => $matchCard->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($client);

        // First rate succeeds
        $this->postJson("/api/v1/works/{$work->uuid}/rate", [
            'score' => 5,
        ])->assertStatus(200);

        // Second rate returns 422
        $this->postJson("/api/v1/works/{$work->uuid}/rate", [
            'score' => 4,
        ])->assertStatus(422)
          ->assertJson(['message' => 'Ya calificaste este trabajo.']);
    }

    public function test_cannot_rate_incomplete_work_returns_422(): void
    {
        $client = $this->createTestUser('client');
        $providerUser = $this->createTestUser('provider');

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $providerUser->id,
            'bio' => 'Provider',
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'raw_prompt' => 'Solicitud',
            'status' => 'active',
        ]);

        $matchSession = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $matchSession->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 1.0,
            'card_status' => 'accepted',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'match_card_id' => $matchCard->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($client);

        $response = $this->postJson("/api/v1/works/{$work->uuid}/rate", [
            'score' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Solo se pueden calificar trabajos completados.']);
    }

    public function test_six_failed_login_attempts_trigger_rate_limiting_429(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'wrong@test.com',
                'password' => 'wrongpassword',
            ])->assertStatus(422);
        }

        // 6th attempt triggers 429 Too Many Requests
        $this->postJson('/api/v1/auth/login', [
            'email' => 'wrong@test.com',
            'password' => 'wrongpassword',
        ])->assertStatus(429);
    }
}
