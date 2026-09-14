<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\ServiceRequests\Enums\RequestUrgency;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\RatingModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Infrastructure\Persistence\Eloquent\WorkQuoteModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkQuoteCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function createUser(string $role = 'client'): UserModel
    {
        return UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'User ' . rand(100, 999),
            'email' => 'test_' . Str::random(8) . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    private function createWork(UserModel $client, ProviderProfileModel $provider): WorkModel
    {
        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => CategoryModel::first()?->id ?? 1,
            'raw_prompt' => 'Reparación de filtración',
            'urgency' => RequestUrgency::Today,
            'status' => \App\Domain\ServiceRequests\Enums\RequestStatus::ProviderSelected,
        ]);

        return WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'status' => WorkStatus::Confirmed,
        ]);
    }

    private function createProvider(UserModel $providerUser): ProviderProfileModel
    {
        $provider = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $provider->id,
            'overall_verification_status' => 'approved',
        ]);

        return $provider;
    }

    public function test_cannot_complete_work_without_client_accepted_quote(): void
    {
        $client = $this->createUser('client');
        $providerUser = $this->createUser('provider');
        $provider = $this->createProvider($providerUser);

        $work = $this->createWork($client, $provider);

        // Attempt completion without accepted quote -> Must return HTTP 422
        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(422);
        $this->assertStringContainsString('No se puede finalizar el trabajo sin un presupuesto aceptado', $response->json('message') ?? json_encode($response->json()));

        $work->refresh();
        $this->assertNotEquals(WorkStatus::Completed, $work->status);
    }

    public function test_professional_can_create_structured_quote(): void
    {
        $client = $this->createUser('client');
        $providerUser = $this->createUser('provider');
        $provider = $this->createProvider($providerUser);

        $work = $this->createWork($client, $provider);

        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes", [
                'amount' => 15000,
                'currency' => 'ARS',
                'breakdown_items' => [
                    ['concept' => 'Mano de obra', 'price' => 10000],
                    ['concept' => 'Materiales repuesto', 'price' => 5000],
                ],
                'estimated_hours' => 2,
                'terms_conditions' => 'Garantía por 30 días',
            ]);

        $response->assertStatus(201);
        $quoteUuid = $response->json('data.id');

        $quote = WorkQuoteModel::where('uuid', $quoteUuid)->first();
        $this->assertNotNull($quote);
        $this->assertEquals(15000, $quote->amount);
        $this->assertEquals('pending', $quote->status);
    }

    public function test_client_accepts_quote_updates_agreed_price_and_allows_work_completion(): void
    {
        $client = $this->createUser('client');
        $providerUser = $this->createUser('provider');
        $provider = $this->createProvider($providerUser);

        $work = $this->createWork($client, $provider);

        // 1. Provider sends quote
        $createQuoteRes = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes", [
                'amount' => 22000,
                'breakdown_items' => [['concept' => 'Servicio completo', 'price' => 22000]],
            ]);

        $quoteUuid = $createQuoteRes->json('data.id');

        // 2. Client accepts quote
        $acceptRes = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes/{$quoteUuid}/accept");

        $acceptRes->assertStatus(200);

        $work->refresh();
        $this->assertEquals(22000, (float)$work->agreed_price);

        // 3. Work completion now succeeds!
        $completeRes = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete");

        $completeRes->assertStatus(200);

        $work->refresh();
        $this->assertEquals(WorkStatus::Completed, $work->status);
    }

    public function test_rating_remains_available_after_completion(): void
    {
        $client = $this->createUser('client');
        $providerUser = $this->createUser('provider');
        $provider = $this->createProvider($providerUser);

        $work = $this->createWork($client, $provider);
        $quote = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $provider->id,
            'client_id' => $client->id,
            'amount' => 10000,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        $work->applyAcceptedQuote($quote);
        $work->transitionTo(WorkStatus::Completed);

        // Submit review
        $rateRes = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/rate", [
                'score' => 5,
                'comment' => 'Excelente atención y trabajo profesional.',
            ]);

        $rateRes->assertStatus(200);

        $this->assertDatabaseHas('ratings', [
            'work_id' => $work->id,
            'reviewer_id' => $client->id,
            'score' => 5,
        ]);
    }
}
