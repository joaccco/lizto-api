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

class MatchingAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    public function test_provider_confirm_work_request_resolves_match_card_id_without_500_error(): void
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

        // 1. Juan creates ServiceRequest
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Pérdida de agua urgente',
            'status' => 'matching_active',
        ]);

        // 2. Matching session & card created for Roberto
        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        $card = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.95,
            'card_status' => 'shown',
        ]);

        // 3. Roberto accepts from /provider/work-requests/{id}/confirm
        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm", [
                'estimated_duration_min' => 90,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.estimated_duration_min', 90);

        // 4. Assert Work created with match_card_id resolved
        $work = WorkModel::where('service_request_id', $serviceRequest->id)->first();
        $this->assertNotNull($work);
        $this->assertEquals($card->id, $work->match_card_id);
        $this->assertEquals($client->id, $work->client_id);
        $this->assertEquals($providerProfile->id, $work->provider_id);

        // Assert Conversation created
        $this->assertDatabaseHas('conversations', [
            'work_id' => $work->id,
            'service_request_id' => $serviceRequest->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
        ]);

        // Assert MatchCard status updated to accepted
        $card->refresh();
        $cardStatusVal = $card->card_status instanceof \BackedEnum ? $card->card_status->value : $card->card_status;
        $this->assertEquals('accepted', $cardStatusVal);
    }
}
