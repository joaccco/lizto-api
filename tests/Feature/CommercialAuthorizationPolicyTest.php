<?php

namespace Tests\Feature;

use App\Application\Offers\Actions\AcceptOfferAction;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Infrastructure\Persistence\Eloquent\WorkQuoteModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercialAuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createClientAndProvider(): array
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Test',
            'email' => 'client_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Test',
            'email' => 'provider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::where('slug', 'plomeria')->first() ?? CategoryModel::first();

        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'category_id' => $category->id,
            'bio' => 'Plomero profesional',
            'is_verified' => true,
            'coverage_radius_km' => 15,
        ]);

        return [$client, $providerUser, $providerProfile, $category];
    }

    private function createServiceRequestAndSession($client, $category): array
    {
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de cañería',
            'status' => 'pending_matching',
        ]);

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        return [$serviceRequest, $session];
    }

    public function test_work_without_accepted_quote_and_not_legacy_cannot_be_completed(): void
    {
        [$client, $providerUser, $providerProfile, $category] = $this->createClientAndProvider();
        [$serviceRequest, $session] = $this->createServiceRequestAndSession($client, $category);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        // Manually create work with agreed_price set directly in DB, but no accepted quote and is_legacy_pre_quote = false
        $work = new WorkModel();
        $work->uuid = (string) Str::uuid();
        $work->service_request_id = $serviceRequest->id;
        $work->match_card_id = $matchCard->id;
        $work->client_id = $client->id;
        $work->provider_id = $providerProfile->id;
        $work->status = WorkStatus::InProgress;
        $work->agreed_price = 15000.00;
        $work->is_legacy_pre_quote = false;
        $work->save();

        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede finalizar el trabajo sin un presupuesto aceptado por el cliente.');

        $work->refresh();
        $this->assertEquals(WorkStatus::InProgress->value, $work->status->value);
    }

    public function test_accepting_exact_price_offer_generates_accepted_quote_and_work_can_complete(): void
    {
        [$client, $providerUser, $providerProfile, $category] = $this->createClientAndProvider();
        [$serviceRequest, $session] = $this->createServiceRequestAndSession($client, $category);

        $offer = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $providerProfile->id,
            'status' => OfferStatus::Pending,
            'pricing_mode' => 'quoted',
            'proposed_price' => 25000.00,
            'currency_code' => 'ARS',
        ]);

        /** @var AcceptOfferAction $action */
        $action = app(AcceptOfferAction::class);
        $result = $action->execute($offer);
        $work = $result['work'];

        $this->assertDatabaseHas('work_quotes', [
            'work_id' => $work->id,
            'status' => 'accepted',
            'origin' => 'offer_acceptance',
            'amount' => 25000.00,
        ]);

        $this->assertEquals(25000.00, (float) $work->agreed_price);

        // Can complete work without extra steps
        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_accepting_technical_visit_offer_does_not_generate_quote_and_work_cannot_complete(): void
    {
        [$client, $providerUser, $providerProfile, $category] = $this->createClientAndProvider();
        [$serviceRequest, $session] = $this->createServiceRequestAndSession($client, $category);

        $offer = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $providerProfile->id,
            'status' => OfferStatus::Pending,
            'pricing_mode' => 'requires_visit',
            'proposed_price' => 0.00,
        ]);

        /** @var AcceptOfferAction $action */
        $action = app(AcceptOfferAction::class);
        $result = $action->execute($offer);
        $work = $result['work'];

        $this->assertDatabaseMissing('work_quotes', [
            'work_id' => $work->id,
        ]);

        // Trying to complete fails
        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede finalizar el trabajo sin un presupuesto aceptado por el cliente.');
    }

    public function test_legacy_work_with_is_legacy_flag_can_be_completed(): void
    {
        [$client, $providerUser, $providerProfile, $category] = $this->createClientAndProvider();
        [$serviceRequest, $session] = $this->createServiceRequestAndSession($client, $category);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        $work = new WorkModel();
        $work->uuid = (string) Str::uuid();
        $work->service_request_id = $serviceRequest->id;
        $work->match_card_id = $matchCard->id;
        $work->client_id = $client->id;
        $work->provider_id = $providerProfile->id;
        $work->status = WorkStatus::InProgress;
        $work->agreed_price = 10000.00;
        $work->is_legacy_pre_quote = true; // Flagged by migration
        $work->save();

        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_unauthorized_provider_cannot_issue_quote(): void
    {
        [$client, $providerUser, $providerProfile, $category] = $this->createClientAndProvider();
        [$serviceRequest, $session] = $this->createServiceRequestAndSession($client, $category);

        $otherProviderUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Otro Proveedor',
            'email' => 'other_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        $work = new WorkModel();
        $work->uuid = (string) Str::uuid();
        $work->service_request_id = $serviceRequest->id;
        $work->match_card_id = $matchCard->id;
        $work->client_id = $client->id;
        $work->provider_id = $providerProfile->id;
        $work->status = WorkStatus::InProgress;
        $work->save();

        $response = $this->actingAs($otherProviderUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes", [
                'amount' => 18000.00,
            ]);

        $response->assertStatus(403);
    }

    public function test_non_client_cannot_accept_quote(): void
    {
        [$client, $providerUser, $providerProfile, $category] = $this->createClientAndProvider();
        [$serviceRequest, $session] = $this->createServiceRequestAndSession($client, $category);

        $strangerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario Extraño',
            'email' => 'stranger_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        $work = new WorkModel();
        $work->uuid = (string) Str::uuid();
        $work->service_request_id = $serviceRequest->id;
        $work->match_card_id = $matchCard->id;
        $work->client_id = $client->id;
        $work->provider_id = $providerProfile->id;
        $work->status = WorkStatus::InProgress;
        $work->save();

        $quote = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 12000.00,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($strangerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes/{$quote->uuid}/accept");

        $response->assertStatus(403);
    }

    public function test_expired_quote_cannot_be_accepted(): void
    {
        [$client, $providerUser, $providerProfile, $category] = $this->createClientAndProvider();
        [$serviceRequest, $session] = $this->createServiceRequestAndSession($client, $category);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        $work = new WorkModel();
        $work->uuid = (string) Str::uuid();
        $work->service_request_id = $serviceRequest->id;
        $work->match_card_id = $matchCard->id;
        $work->client_id = $client->id;
        $work->provider_id = $providerProfile->id;
        $work->status = WorkStatus::InProgress;
        $work->save();

        $quote = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 12000.00,
            'valid_until' => now()->subDay(), // Expired
            'status' => 'pending',
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes/{$quote->uuid}/accept");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El presupuesto ha vencido y no puede ser aceptado.');
    }

    public function test_rejected_quote_cannot_be_accepted_later(): void
    {
        [$client, $providerUser, $providerProfile, $category] = $this->createClientAndProvider();
        [$serviceRequest, $session] = $this->createServiceRequestAndSession($client, $category);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $providerProfile->id,
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        $work = new WorkModel();
        $work->uuid = (string) Str::uuid();
        $work->service_request_id = $serviceRequest->id;
        $work->match_card_id = $matchCard->id;
        $work->client_id = $client->id;
        $work->provider_id = $providerProfile->id;
        $work->status = WorkStatus::InProgress;
        $work->save();

        $quote = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 12000.00,
            'status' => 'rejected',
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes/{$quote->uuid}/accept");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Solo se pueden aceptar o rechazar presupuestos en estado pendiente.');
    }
}
