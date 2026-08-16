<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClarificationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createTestUser(): UserModel
    {
        return UserModel::create([
            'name' => 'Test Client',
            'email' => 'client_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_full_clarification_flow_create_answer_brief(): void
    {
        $user = $this->createTestUser();

        // 1. Create request via clarification endpoint
        $resStore = $this->actingAs($user, 'sanctum')->postJson('/api/v1/clarification/service-requests', [
            'prompt' => 'Necesito un plomero por una pérdida de agua en la cocina',
            'is_remote' => false,
            'urgency' => 'immediate',
        ]);

        $resStore->assertStatus(201)
            ->assertJsonPath('data.category.slug', 'plomeria')
            ->assertJsonPath('data.service_type.slug', 'water_leak');

        $reqUuid = $resStore->json('data.id');
        $nextQId = $resStore->json('data.next_question.id');
        $this->assertNotNull($nextQId);

        // 2. Answer first question
        $resAns1 = $this->actingAs($user, 'sanctum')->postJson("/api/v1/clarification/service-requests/{$reqUuid}/answers", [
            'question_id' => $nextQId,
            'answer_value' => 'cocina',
        ]);

        $resAns1->assertStatus(200)
            ->assertJsonPath('data.question_key', 'location');

        // 3. Confirm brief
        $resBrief = $this->actingAs($user, 'sanctum')->postJson("/api/v1/clarification/service-requests/{$reqUuid}/brief/confirm");

        $resBrief->assertStatus(200)
            ->assertJsonPath('data.is_confirmed', true);
    }

    public function test_manual_category_override(): void
    {
        $user = $this->createTestUser();

        $resStore = $this->actingAs($user, 'sanctum')->postJson('/api/v1/clarification/service-requests', [
            'prompt' => 'Necesito arreglar la luz de mi casa',
        ]);

        $reqUuid = $resStore->json('data.id');

        // Manual override to Cerrajeria
        $resOverride = $this->actingAs($user, 'sanctum')->postJson("/api/v1/clarification/service-requests/{$reqUuid}/classify", [
            'category_slug' => 'cerrajeria',
            'service_type_slug' => 'opening',
        ]);

        $resOverride->assertStatus(200)
            ->assertJsonPath('data.category_slug', 'cerrajeria')
            ->assertJsonPath('data.service_type_slug', 'opening');
    }
}
