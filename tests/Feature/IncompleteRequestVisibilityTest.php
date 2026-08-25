<?php

namespace Tests\Feature;

use App\Domain\ServiceRequests\Enums\RequestStatus;
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

class IncompleteRequestVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createSetup(): array
    {
        $client = UserModel::create(['name' => 'Cliente', 'email' => 'client_' . Str::random(5) . '@test.com', 'password' => bcrypt('password')]);
        $providerUser1 = UserModel::create(['name' => 'Pro1', 'email' => 'pro1_' . Str::random(5) . '@test.com', 'password' => bcrypt('password')]);
        $providerUser2 = UserModel::create(['name' => 'Pro2', 'email' => 'pro2_' . Str::random(5) . '@test.com', 'password' => bcrypt('password')]);
        $category = CategoryModel::first();

        $provider1 = ProviderProfileModel::create([
            'user_id' => $providerUser1->id, 'category_id' => $category->id, 'uuid' => (string) Str::uuid(), 'bio' => 'Pro1', 'is_verified' => true, 'availability_status' => 'available',
        ]);
        $provider1->categories()->create(['category_id' => $category->id]);

        $provider2 = ProviderProfileModel::create([
            'user_id' => $providerUser2->id, 'category_id' => $category->id, 'uuid' => (string) Str::uuid(), 'bio' => 'Pro2', 'is_verified' => true, 'availability_status' => 'available',
        ]);
        $provider2->categories()->create(['category_id' => $category->id]);

        return [$client, $providerUser1, $provider1, $providerUser2, $provider2, $category];
    }

    /** 1. Una solicitud recién creada (pending_survey) no aparece en el panel de ningún profesional. */
    public function test_newly_created_incomplete_request_is_not_visible_to_any_provider(): void
    {
        [$client, $providerUser1, $provider1, $providerUser2, $provider2, $category] = $this->createSetup();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Solicitud incompleta',
            'status' => RequestStatus::PendingSurvey,
        ]);

        $res1 = $this->actingAs($providerUser1, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $res1->assertStatus(200);
        $this->assertEmpty($res1->json('data'));

        $res2 = $this->actingAs($providerUser2, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $res2->assertStatus(200);
        $this->assertEmpty($res2->json('data'));
    }

    /** 2. La misma solicitud, una vez completada por el cliente (pending_matching), sí aparece. */
    public function test_request_becomes_visible_once_completed_by_client(): void
    {
        [$client, $providerUser1, $provider1, $providerUser2, $provider2, $category] = $this->createSetup();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Solicitud lista',
            'status' => RequestStatus::PendingMatching,
        ]);

        $res = $this->actingAs($providerUser1, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $res->assertStatus(200);
        $items = $res->json('data');
        $this->assertCount(1, $items);
        $this->assertEquals($sr->uuid, $items[0]['id']);
    }

    /** 3. Una solicitud completa dirigida explícitamente a un profesional le sigue apareciendo solo a él. */
    public function test_complete_request_directed_to_specific_provider_is_visible_only_to_him(): void
    {
        [$client, $providerUser1, $provider1, $providerUser2, $provider2, $category] = $this->createSetup();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Solicitud para Pro1',
            'status' => RequestStatus::PendingMatching,
        ]);

        $session = MatchSessionModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'status' => 'active']);
        MatchCardModel::create(['match_session_id' => $session->id, 'provider_id' => $provider1->id, 'rank_position' => 1, 'score_total' => 0.9, 'card_status' => 'shown']);

        $res1 = $this->actingAs($providerUser1, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $res1->assertStatus(200);
        $this->assertCount(1, $res1->json('data'));

        $res2 = $this->actingAs($providerUser2, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $res2->assertStatus(200);
        $this->assertEmpty($res2->json('data'));
    }
}
