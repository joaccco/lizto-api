<?php

namespace Tests\Feature;

use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewReputationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function createUser(): UserModel
    {
        return UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario Test ' . rand(100, 999),
            'email' => 'user_' . Str::random(8) . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_client_can_review_completed_work_and_updates_provider_reputation(): void
    {
        $client = $this->createUser();
        $providerUser = $this->createUser();
        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'is_verified' => true,
            'avg_rating' => 0,
            'total_reviews' => 0,
        ]);

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'raw_prompt' => 'Apertura de puerta de emergencia',
            'status' => 'completed',
        ]);

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
        ]);

        $card = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'match_card_id' => $card->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Completed,
            'agreed_price' => 25000,
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/rate", [
                'score' => 5,
                'comment' => 'Excelente trabajo, súper puntual y prolijo.',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.score', 5);

        $providerProfile->refresh();
        $this->assertEquals(5.0, (float) $providerProfile->avg_rating);
        $this->assertEquals(1, $providerProfile->total_reviews);
    }

    public function test_cannot_review_work_that_is_not_completed(): void
    {
        $client = $this->createUser();
        $providerUser = $this->createUser();
        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'is_verified' => true,
        ]);

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'raw_prompt' => 'Apertura de puerta',
            'status' => \App\Domain\ServiceRequests\Enums\RequestStatus::Active,
        ]);

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
        ]);

        $card = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'match_card_id' => $card->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::InProgress,
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/rate", [
                'score' => 4,
                'comment' => 'En proceso',
            ]);

        $response->assertStatus(422);
    }
}
