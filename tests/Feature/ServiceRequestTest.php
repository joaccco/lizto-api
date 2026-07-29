<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SurveyQuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(SurveyQuestionSeeder::class);
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

    public function test_authenticated_client_can_create_service_request(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/requests', [
            'prompt' => 'necesito un cerrajero urgente',
            'urgency' => 'immediate',
            'category_slug' => 'cerrajeria',
            'is_remote' => false,
            'location' => [
                'lat' => -27.4692,
                'lng' => -58.8306,
                'address' => 'Corrientes, Argentina',
            ],
            'parsed_intent' => [
                'raw_intent' => 'cerrajero urgente',
                'confidence' => 0.9,
                'detected_keywords' => ['cerrajero', 'urgente'],
                'ambiguity_level' => 'low',
                'clarification_needed' => [],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_survey')
            ->assertJsonPath('data.urgency', 'immediate')
            ->assertJsonPath('data.category.slug', 'cerrajeria')
            ->assertJsonPath('message', 'Solicitud creada correctamente.');

        $this->assertDatabaseHas('service_requests', [
            'client_id' => $user->id,
            'raw_prompt' => 'necesito un cerrajero urgente',
            'status' => 'pending_survey',
        ]);
    }

    public function test_service_request_created_with_status_pending_survey(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/requests', [
            'prompt' => 'necesito un electricista',
            'category_slug' => 'electricidad',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_survey');
    }

    public function test_client_can_submit_survey_and_status_changes_to_pending_matching(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/requests', [
            'prompt' => 'necesito un cerrajero urgente',
            'category_slug' => 'cerrajeria',
        ]);

        $requestId = $createResponse->json('data.id');

        $surveyResponse = $this->postJson("/api/v1/requests/{$requestId}/survey", [
            'answers' => [
                [
                    'question_key' => 'property_type',
                    'question_text' => '¿Es para una casa, departamento...?',
                    'answer_value' => 'casa',
                    'question_id' => 1,
                ],
                [
                    'question_key' => 'service_type',
                    'question_text' => '¿Qué necesitás exactamente?',
                    'answer_value' => 'apertura',
                    'question_id' => 2,
                ],
            ],
        ]);

        $surveyResponse->assertStatus(200)
            ->assertJsonPath('data.request_id', $requestId)
            ->assertJsonPath('data.status', 'pending_matching')
            ->assertJsonPath('data.structured_data.property_type', 'casa')
            ->assertJsonPath('data.structured_data.service_type', 'apertura')
            ->assertJsonPath('message', 'Encuesta completada.');

        $this->assertDatabaseHas('service_requests', [
            'uuid' => $requestId,
            'status' => 'pending_matching',
        ]);
    }
}
