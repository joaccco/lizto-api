<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultipleProviderWorksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    public function test_provider_can_accept_and_manage_multiple_active_works_simultaneously(): void
    {
        $client1 = UserModel::create([
            'name' => 'Cliente Uno',
            'email' => 'client1_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $client2 = UserModel::create([
            'name' => 'Cliente Dos',
            'email' => 'client2_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'name' => 'Roberto Proveedor Multiples',
            'email' => 'roberto_multi_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::where('slug', 'plomeria')->first();

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $providerUser->id,
            'category_id' => $category->id,
            'uuid' => (string) Str::uuid(),
            'bio' => 'Plomero profesional',
            'is_verified' => true,
            'coverage_radius_km' => 15,
        ]);

        // Request 1
        $request1 = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client1->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Pérdida en cocina',
            'status' => 'pending_matching',
        ]);
        $session1 = MatchSessionModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $request1->id, 'status' => 'active']);
        MatchCardModel::create(['match_session_id' => $session1->id, 'provider_id' => $providerProfile->id, 'rank_position' => 1, 'score_total' => 0.9, 'card_status' => 'shown']);

        // Request 2
        $request2 = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client2->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Destape de baño',
            'status' => 'pending_matching',
        ]);
        $session2 = MatchSessionModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $request2->id, 'status' => 'active']);
        MatchCardModel::create(['match_session_id' => $session2->id, 'provider_id' => $providerProfile->id, 'rank_position' => 1, 'score_total' => 0.95, 'card_status' => 'shown']);

        // 1. Confirm Request 1
        $res1 = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/provider/work-requests/{$request1->uuid}/confirm", [
            'estimated_duration_min' => 45,
        ]);
        $res1->assertStatus(200);
        $work1Uuid = $res1->json('data.work_id');

        // 2. Confirm Request 2 (Provider accepts a SECOND work)
        $res2 = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/provider/work-requests/{$request2->uuid}/confirm", [
            'estimated_duration_min' => 90,
        ]);
        $res2->assertStatus(200);
        $work2Uuid = $res2->json('data.work_id');

        $this->assertNotEquals($work1Uuid, $work2Uuid);

        // 3. Provider fetches active work requests list
        $resList = $this->actingAs($providerUser, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $resList->assertStatus(200);

        $items = $resList->json('data');
        $this->assertGreaterThanOrEqual(2, count($items));

        $confirmedWorks = array_filter($items, fn($i) => $i['status'] === 'confirmed');
        $this->assertCount(2, $confirmedWorks);

        // 4. Complete Work 1 -> Work 2 remains active
        $resComp1 = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/works/{$work1Uuid}/complete");
        $resComp1->assertStatus(200);

        $work1 = WorkModel::where('uuid', $work1Uuid)->first();
        $work2 = WorkModel::where('uuid', $work2Uuid)->first();

        $this->assertEquals('completed', $work1->status->value);
        $this->assertEquals('confirmed', $work2->status->value);

        // 5. Complete Work 2
        $resComp2 = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/works/{$work2Uuid}/complete");
        $resComp2->assertStatus(200);

        $work2->refresh();
        $this->assertEquals('completed', $work2->status->value);
    }
}
