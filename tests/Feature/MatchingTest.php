<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ProviderSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SurveyQuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(SurveyQuestionSeeder::class);
        $this->seed(ProviderSeeder::class);
    }

    private function createTestUser(): UserModel
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Client',
            'email' => 'client-' . Str::random(6) . '@test.com',
            'password' => 'password123',
            'status' => 'active',
        ]);
        $user->assignRole('client');
        return $user;
    }

    public function test_can_create_match_session_from_service_request(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/requests', [
            'prompt' => 'necesito un cerrajero urgente',
            'urgency' => 'immediate',
            'category_slug' => 'cerrajeria',
            'location' => [
                'lat' => -27.4692,
                'lng' => -58.8306,
                'address' => 'Corrientes, Argentina',
            ],
        ]);

        $requestId = $createResponse->json('data.id');

        $this->postJson("/api/v1/requests/{$requestId}/survey", [
            'answers' => [
                [
                    'question_key' => 'service_type',
                    'question_text' => '¿Qué necesitás?',
                    'answer_value' => 'apertura',
                ],
            ],
        ]);

        $matchResponse = $this->postJson("/api/v1/requests/{$requestId}/match");

        $matchResponse->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'session_id',
                    'total_providers',
                    'cards',
                ],
            ]);

        $this->assertDatabaseHas('service_requests', [
            'uuid' => $requestId,
            'status' => 'matching_active',
        ]);
    }

    public function test_match_session_returns_ranked_providers(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/requests', [
            'prompt' => 'necesito un cerrajero urgente',
            'urgency' => 'immediate',
            'category_slug' => 'cerrajeria',
            'location' => [
                'lat' => -27.4692,
                'lng' => -58.8306,
            ],
        ]);

        $requestId = $createResponse->json('data.id');

        $matchResponse = $this->postJson("/api/v1/requests/{$requestId}/match");

        $matchResponse->assertStatus(201);
        $cards = $matchResponse->json('data.cards');

        $this->assertNotEmpty($cards);
        $this->assertEquals(1, $cards[0]['rank_position']);
        $this->assertGreaterThan(0, $cards[0]['score_total']);
    }

    public function test_client_can_accept_a_card(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/requests', [
            'prompt' => 'necesito un cerrajero urgente',
            'category_slug' => 'cerrajeria',
            'location' => [
                'lat' => -27.4692,
                'lng' => -58.8306,
            ],
        ]);
        $requestId = $createResponse->json('data.id');

        $matchResponse = $this->postJson("/api/v1/requests/{$requestId}/match");
        $sessionId = $matchResponse->json('data.session_id');
        $cardId = $matchResponse->json('data.cards.0.card_id');

        $acceptResponse = $this->postJson("/api/v1/match-sessions/{$sessionId}/cards/{$cardId}/accept");

        $acceptResponse->assertStatus(200)
            ->assertJsonPath('data.card_id', $cardId)
            ->assertJsonPath('data.card_status', 'accepted')
            ->assertJsonPath('message', 'Tarjeta aceptada.');

        $this->assertDatabaseHas('service_requests', [
            'uuid' => $requestId,
            'status' => 'provider_selected',
        ]);
    }

    public function test_client_can_reject_a_card(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/requests', [
            'prompt' => 'necesito un cerrajero urgente',
            'category_slug' => 'cerrajeria',
            'location' => [
                'lat' => -27.4692,
                'lng' => -58.8306,
            ],
        ]);
        $requestId = $createResponse->json('data.id');

        $matchResponse = $this->postJson("/api/v1/requests/{$requestId}/match");
        $sessionId = $matchResponse->json('data.session_id');
        $cardId = $matchResponse->json('data.cards.0.card_id');

        $rejectResponse = $this->postJson("/api/v1/match-sessions/{$sessionId}/cards/{$cardId}/reject");

        $rejectResponse->assertStatus(200)
            ->assertJsonPath('data.rejected_card_id', $cardId)
            ->assertJsonPath('message', 'Tarjeta rechazada.');

        $this->assertDatabaseHas('match_cards', [
            'id' => $cardId,
            'card_status' => 'rejected',
        ]);
    }

    public function test_client_can_recover_rejected_card(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/requests', [
            'prompt' => 'necesito un cerrajero urgente',
            'category_slug' => 'cerrajeria',
            'location' => [
                'lat' => -27.4692,
                'lng' => -58.8306,
            ],
        ]);
        $requestId = $createResponse->json('data.id');

        $matchResponse = $this->postJson("/api/v1/requests/{$requestId}/match");
        $sessionId = $matchResponse->json('data.session_id');
        $cardId = $matchResponse->json('data.cards.0.card_id');

        $this->postJson("/api/v1/match-sessions/{$sessionId}/cards/{$cardId}/reject");

        $recoverResponse = $this->postJson("/api/v1/match-sessions/{$sessionId}/cards/{$cardId}/recover");

        $recoverResponse->assertStatus(200)
            ->assertJsonPath('data.card_id', $cardId)
            ->assertJsonPath('data.card_status', 'recovered')
            ->assertJsonPath('message', 'Tarjeta recuperada.');
    }
}
