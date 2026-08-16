<?php

namespace Tests\Unit;

use App\Domain\Clarification\Enums\QuestionnaireVersionStatus;
use App\Domain\Clarification\Enums\QuoteReadiness;
use App\Domain\Clarification\Services\DynamicQuestionEngine;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\QuestionConditionModel;
use App\Infrastructure\Persistence\Eloquent\QuestionModel;
use App\Infrastructure\Persistence\Eloquent\QuestionnaireVersionModel;
use App\Infrastructure\Persistence\Eloquent\RequestAnswerModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\ServiceTypeModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DynamicQuestionEngineTest extends TestCase
{
    use RefreshDatabase;

    private DynamicQuestionEngine $engine;
    private UserModel $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DynamicQuestionEngine();
        $this->user = UserModel::create([
            'name' => 'Test Client',
            'email' => 'testclient_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_service_type_with_always_requires_evaluation_never_reaches_ready_for_quote(): void
    {
        $category = CategoryModel::create(['name' => 'Abogacía', 'slug' => 'abogacia']);
        $serviceType = ServiceTypeModel::create([
            'category_id' => $category->id,
            'name' => 'Derecho Laboral',
            'slug' => 'labor',
            'always_requires_evaluation' => true,
        ]);

        $version = QuestionnaireVersionModel::create([
            'service_type_id' => $serviceType->id,
            'version_number' => 1,
            'status' => QuestionnaireVersionStatus::Published,
            'published_at' => now(),
        ]);
        $serviceType->update(['current_version_id' => $version->id]);

        $q1 = QuestionModel::create([
            'questionnaire_version_id' => $version->id,
            'question_key' => 'labor_issue',
            'question_text' => '¿Cuál es el problema laboral?',
            'input_type' => 'short_text',
            'is_required' => true,
            'position' => 1,
            'used_for_matching' => true,
            'used_for_quote' => true,
        ]);

        $request = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->user->id,
            'category_id' => $category->id,
            'service_type_id' => $serviceType->id,
            'questionnaire_version_id' => $version->id,
            'raw_prompt' => 'Tuve un despido injustificado',
            'status' => 'pending_survey',
        ]);

        // Initially insufficient information
        $readinessInitial = $this->engine->calculateQuoteReadiness($request);
        $this->assertEquals(QuoteReadiness::InsufficientInformation, $readinessInitial);

        // Answer question
        RequestAnswerModel::create([
            'service_request_id' => $request->id,
            'question_id' => $q1->id,
            'question_key' => 'labor_issue',
            'answer_value' => 'Despido incausado',
            'source' => \App\Domain\Clarification\Enums\AnswerSource::User,
        ]);

        $request->refresh();
        $readinessAfter = $this->engine->calculateQuoteReadiness($request);

        // MUST be RequiresProfessionalEvaluation, NEVER ReadyForQuote
        $this->assertEquals(QuoteReadiness::RequiresProfessionalEvaluation, $readinessAfter);
        $this->assertNotEquals(QuoteReadiness::ReadyForQuote, $readinessAfter);
    }

    public function test_evaluates_question_conditions_correctly(): void
    {
        $category = CategoryModel::create(['name' => 'Plomería', 'slug' => 'plomeria']);
        $serviceType = ServiceTypeModel::create([
            'category_id' => $category->id,
            'name' => 'Pérdida de agua',
            'slug' => 'water_leak',
            'always_requires_evaluation' => false,
        ]);

        $version = QuestionnaireVersionModel::create([
            'service_type_id' => $serviceType->id,
            'version_number' => 1,
            'status' => QuestionnaireVersionStatus::Published,
            'published_at' => now(),
        ]);
        $serviceType->update(['current_version_id' => $version->id]);

        $q1 = QuestionModel::create([
            'questionnaire_version_id' => $version->id,
            'question_key' => 'active_now',
            'question_text' => '¿La pérdida está activa?',
            'input_type' => 'boolean',
            'position' => 1,
        ]);

        $q2 = QuestionModel::create([
            'questionnaire_version_id' => $version->id,
            'question_key' => 'can_shut_valve',
            'question_text' => '¿Podés cerrar la llave?',
            'input_type' => 'boolean',
            'position' => 2,
        ]);

        QuestionConditionModel::create([
            'question_id' => $q2->id,
            'depends_on_question_id' => $q1->id,
            'depends_on_question_key' => 'active_now',
            'operator' => 'equals',
            'expected_value' => true,
        ]);

        $request = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->user->id,
            'category_id' => $category->id,
            'service_type_id' => $serviceType->id,
            'questionnaire_version_id' => $version->id,
            'raw_prompt' => 'Pérdida en cocina',
            'status' => 'pending_survey',
        ]);

        // When q1 not answered, q2 condition evaluates to false
        $next = $this->engine->getNextQuestion($request);
        $this->assertEquals($q1->id, $next->id);

        // Answer q1 as false
        RequestAnswerModel::create([
            'service_request_id' => $request->id,
            'question_id' => $q1->id,
            'question_key' => 'active_now',
            'answer_value' => false,
            'source' => \App\Domain\Clarification\Enums\AnswerSource::User,
        ]);

        $request->refresh();
        $nextFalse = $this->engine->getNextQuestion($request);
        $this->assertNull($nextFalse); // q2 skipped because active_now is false

        // Update q1 answer to true
        RequestAnswerModel::where('service_request_id', $request->id)
            ->where('question_id', $q1->id)
            ->update(['answer_value' => json_encode(true)]);

        $request->refresh();
        $nextTrue = $this->engine->getNextQuestion($request);
        $this->assertEquals($q2->id, $nextTrue->id); // q2 active now
    }
}
