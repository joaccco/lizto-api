<?php

namespace Tests\Feature;

use App\Domain\Clarification\Enums\QuestionnaireVersionStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\QuestionModel;
use App\Infrastructure\Persistence\Eloquent\QuestionnaireVersionModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\ServiceTypeModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionnaireVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_service_requests_remain_unaffected_when_new_version_is_published(): void
    {
        $user = UserModel::create([
            'name' => 'Test Client',
            'email' => 'versioning_client@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::create(['name' => 'Plomería', 'slug' => 'plomeria']);
        $serviceType = ServiceTypeModel::create([
            'category_id' => $category->id,
            'name' => 'Pérdida de agua',
            'slug' => 'water_leak',
        ]);

        // 1. Create v1 in draft -> publish v1
        $v1 = QuestionnaireVersionModel::create([
            'service_type_id' => $serviceType->id,
            'version_number' => 1,
            'status' => QuestionnaireVersionStatus::Draft,
        ]);

        $qV1 = QuestionModel::create([
            'questionnaire_version_id' => $v1->id,
            'question_key' => 'v1_question',
            'question_text' => 'Pregunta Versión 1',
            'input_type' => 'short_text',
            'position' => 1,
        ]);

        $v1->update([
            'status' => QuestionnaireVersionStatus::Published,
            'published_at' => now(),
        ]);
        $serviceType->update(['current_version_id' => $v1->id]);

        // 2. Create ServiceRequest referencing v1
        $requestV1 = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $user->id,
            'category_id' => $category->id,
            'service_type_id' => $serviceType->id,
            'questionnaire_version_id' => $v1->id,
            'raw_prompt' => 'Pérdida en cocina',
            'status' => 'pending_survey',
        ]);

        $this->assertEquals($v1->id, $requestV1->questionnaire_version_id);
        $this->assertEquals('v1_question', $requestV1->questionnaireVersion->questions->first()->question_key);

        // 3. Create v2 in draft -> publish v2
        $v2 = QuestionnaireVersionModel::create([
            'service_type_id' => $serviceType->id,
            'version_number' => 2,
            'status' => QuestionnaireVersionStatus::Draft,
        ]);

        $qV2 = QuestionModel::create([
            'questionnaire_version_id' => $v2->id,
            'question_key' => 'v2_question',
            'question_text' => 'Pregunta Versión 2',
            'input_type' => 'short_text',
            'position' => 1,
        ]);

        $v2->update([
            'status' => QuestionnaireVersionStatus::Published,
            'published_at' => now(),
        ]);
        $serviceType->update(['current_version_id' => $v2->id]);

        // 4. Verify requestV1 remains pointing to v1 and is unaffected by v2
        $requestV1->refresh();
        $this->assertEquals($v1->id, $requestV1->questionnaire_version_id);
        $this->assertEquals(1, $requestV1->questionnaireVersion->version_number);
        $this->assertEquals('v1_question', $requestV1->questionnaireVersion->questions->first()->question_key);

        // Verify new ServiceRequests get v2
        $requestV2 = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $user->id,
            'category_id' => $category->id,
            'service_type_id' => $serviceType->id,
            'questionnaire_version_id' => $serviceType->currentVersion->id,
            'raw_prompt' => 'Pérdida nueva',
            'status' => 'pending_survey',
        ]);

        $this->assertEquals($v2->id, $requestV2->questionnaire_version_id);
        $this->assertEquals(2, $requestV2->questionnaireVersion->version_number);
        $this->assertEquals('v2_question', $requestV2->questionnaireVersion->questions->first()->question_key);
    }
}
