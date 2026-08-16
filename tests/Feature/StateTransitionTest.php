<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StateTransitionTest extends TestCase
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

    public function test_invalid_state_transition_returns_409(): void
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
            'raw_prompt' => 'Solicitud cancelada',
            'status' => 'cancelled',
        ]);

        $matchSession = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'cancelled',
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
            'status' => 'cancelled',
        ]);

        Sanctum::actingAs($providerUser);

        // Attempting to complete a cancelled work is an invalid transition -> 409
        $response = $this->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(409)
            ->assertJsonStructure(['message']);
    }

    public function test_valid_state_transition_returns_200(): void
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
            'raw_prompt' => 'Solicitud en progreso',
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

        Sanctum::actingAs($providerUser);

        // Transitioning from in_progress to completed is valid -> 200
        $response = $this->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
    }
}
