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
use App\Domain\Works\Enums\WorkStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkCompletionUuidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    public function test_work_completion_requires_valid_work_uuid_and_rejects_service_request_uuid(): void
    {
        $client = UserModel::create([
            'name' => 'Juan Solicitante',
            'email' => 'juan_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'name' => 'Roberto Proveedor',
            'email' => 'roberto_' . Str::random(5) . '@test.com',
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

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $providerProfile->id,
            'overall_verification_status' => 'approved',
        ]);

        // 1. Create ServiceRequest
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Desobstrucción lavadero',
            'status' => 'pending_matching',
        ]);

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.95,
            'card_status' => 'shown',
        ]);

        // 2. Provider accepts work request -> returns work_id (work.uuid)
        $resConfirm = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm", [
                'estimated_duration_min' => 60,
            ]);

        $resConfirm->assertStatus(200);
        $workUuid = $resConfirm->json('data.work_id');
        $this->assertNotNull($workUuid);

        // 3. Confirm work.uuid is DIFFERENT from service_request.uuid
        $this->assertNotEquals($serviceRequest->uuid, $workUuid);

        // 5. NEGATIVE TEST: Attempting to call /works/{service_request.uuid}/complete fails with 404 ("Trabajo no encontrado")
        $resNegative = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$serviceRequest->uuid}/complete");

        $resNegative->assertStatus(404)
            ->assertJsonPath('message', 'Trabajo no encontrado.');

        // 4. POSITIVE TEST: Calling /works/{work.uuid}/complete with valid work.uuid succeeds with 200
        $targetWork = WorkModel::where('uuid', $workUuid)->first();
        $quote = \App\Infrastructure\Persistence\Eloquent\WorkQuoteModel::firstOrCreate(
            ['work_id' => $targetWork->id, 'status' => 'accepted'],
            [
                'uuid' => (string) Str::uuid(),
                'provider_id' => $targetWork->provider_id,
                'client_id' => $targetWork->client_id,
                'amount' => 10000,
                'accepted_at' => now(),
            ]
        );
        $targetWork->status = WorkStatus::InProgress;
        $targetWork->save();
        $targetWork->applyAcceptedQuote($quote);

        $resPositive = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$workUuid}/complete");

        $resPositive->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $work = WorkModel::where('uuid', $workUuid)->first();
        $this->assertEquals('completed', $work->status->value);
    }
}
