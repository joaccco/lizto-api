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

class WorkTest extends TestCase
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
            'name' => 'Test User ' . Str::random(4),
            'email' => 'user-' . Str::random(6) . '@test.com',
            'password' => 'password123',
            'status' => 'active',
        ]);
        $user->assignRole($role);
        return $user;
    }

    public function test_complete_work_with_valid_uuid_succeeds(): void
    {
        $client = $this->createTestUser('client');
        $providerUser = $this->createTestUser('provider');
        Sanctum::actingAs($providerUser);

        $category = CategoryModel::first();
        $providerProfile = ProviderProfileModel::create([
            'user_id' => $providerUser->id,
            'bio' => 'Test Provider',
            'availability_status' => 'busy',
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Necesito ayuda',
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

        $workUuid = (string) Str::uuid();
        $work = WorkModel::create([
            'uuid' => $workUuid,
            'service_request_id' => $serviceRequest->id,
            'match_card_id' => $matchCard->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => 'in_progress',
        ]);

        $response = $this->postJson("/api/v1/works/{$workUuid}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('message', 'El trabajo fue marcado como completado.');

        $this->assertDatabaseHas('works', [
            'uuid' => $workUuid,
            'status' => 'completed',
        ]);
    }

    public function test_rate_work_with_non_existent_uuid_returns_404(): void
    {
        $client = $this->createTestUser('client');
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/v1/works/00000000-0000-0000-0000-000000000000/rate', [
            'score' => 5,
            'comment' => 'Excelente servicio',
        ]);

        $response->assertStatus(404)
            ->assertJson(['message' => 'Recurso no encontrado.']);
    }
}
