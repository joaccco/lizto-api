<?php

namespace Tests\Feature;

use Database\Seeders\CategorySeeder;
use Database\Seeders\SurveyQuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParseRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
        $this->seed(SurveyQuestionSeeder::class);
    }

    public function test_parse_detects_cerrajero_urgente_correctly(): void
    {
        $response = $this->postJson('/api/v1/requests/parse', [
            'prompt' => 'necesito un cerrajero urgente',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.parsed_intent.category_slug', 'cerrajeria')
            ->assertJsonPath('data.parsed_intent.urgency', 'immediate')
            ->assertJsonPath('data.mode', 'fast');

        $confidence = $response->json('data.parsed_intent.confidence');
        $this->assertGreaterThanOrEqual(0.80, $confidence);
    }

    public function test_parse_detects_electricista(): void
    {
        $response = $this->postJson('/api/v1/requests/parse', [
            'prompt' => 'necesito un electricista para revisar el tablero',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.parsed_intent.category_slug', 'electricidad');
    }

    public function test_parse_returns_ambiguity_high_when_no_category_detected(): void
    {
        $response = $this->postJson('/api/v1/requests/parse', [
            'prompt' => 'necesito ayuda',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.parsed_intent.ambiguity_level', 'high');

        $confidence = $response->json('data.parsed_intent.confidence');
        $this->assertLessThan(0.60, $confidence);
    }

    public function test_parse_respects_urgency_from_body_over_keywords(): void
    {
        $response = $this->postJson('/api/v1/requests/parse', [
            'prompt' => 'cerrajero',
            'urgency' => 'scheduled',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.parsed_intent.urgency', 'scheduled');
    }

    public function test_parse_returns_suggested_questions_from_db(): void
    {
        $response = $this->postJson('/api/v1/requests/parse', [
            'prompt' => 'necesito un cerrajero',
        ]);

        $response->assertStatus(200);
        $suggestedQuestions = $response->json('data.suggested_questions');
        $this->assertNotEmpty($suggestedQuestions);
    }
}
