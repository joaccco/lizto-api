<?php

namespace Tests\Feature;

use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Infrastructure\Persistence\Eloquent\WorkQuoteModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinalQuoteAuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createWorkFixtures(): array
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente FQ',
            'email' => 'client_fq_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor FQ',
            'email' => 'provider_fq_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $strangerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario Extraño',
            'email' => 'stranger_fq_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::first();

        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'category_id' => $category->id,
            'bio' => 'Plomero',
            'is_verified' => true,
        ]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $providerProfile->id,
            'overall_verification_status' => 'approved',
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Diagnóstico presencial',
            'status' => 'pending_matching',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::PendingDiagnosisQuote,
        ]);

        return [$client, $providerUser, $providerProfile, $strangerUser, $work];
    }

    public function test_non_participant_user_cannot_submit_final_quote(): void
    {
        [$client, $providerUser, $providerProfile, $strangerUser, $work] = $this->createWorkFixtures();

        $response = $this->actingAs($strangerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote", [
                'final_price' => 35000,
            ]);

        $response->assertStatus(403);
        $this->assertNull($work->fresh()->final_price);
    }

    public function test_client_cannot_submit_final_quote(): void
    {
        [$client, $providerUser, $providerProfile, $strangerUser, $work] = $this->createWorkFixtures();

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote", [
                'final_price' => 35000,
            ]);

        $response->assertStatus(403);
        $this->assertNull($work->fresh()->final_price);
    }

    public function test_non_participant_user_cannot_confirm_final_quote(): void
    {
        [$client, $providerUser, $providerProfile, $strangerUser, $work] = $this->createWorkFixtures();

        $work->update(['final_price' => 25000]);

        $response = $this->actingAs($strangerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote/confirm");

        $response->assertStatus(403);
        $this->assertEquals(0, WorkQuoteModel::where('work_id', $work->id)->count());
    }

    public function test_non_participant_user_cannot_reject_final_quote(): void
    {
        [$client, $providerUser, $providerProfile, $strangerUser, $work] = $this->createWorkFixtures();

        $work->update(['final_price' => 25000]);

        $response = $this->actingAs($strangerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote/reject");

        $response->assertStatus(403);
        $this->assertEquals(WorkStatus::PendingDiagnosisQuote->value, $work->fresh()->status->value);
    }

    public function test_assigned_provider_can_submit_final_quote(): void
    {
        [$client, $providerUser, $providerProfile, $strangerUser, $work] = $this->createWorkFixtures();

        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote", [
                'final_price' => 30000,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.final_price', '30000.00');

        $this->assertEquals(30000, (float) $work->fresh()->final_price);
    }

    public function test_client_owner_can_confirm_final_quote(): void
    {
        [$client, $providerUser, $providerProfile, $strangerUser, $work] = $this->createWorkFixtures();

        $work->update(['final_price' => 28000]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote/confirm");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertEquals(WorkStatus::Confirmed->value, $work->fresh()->status->value);
        $this->assertEquals(28000, (float) $work->fresh()->agreed_price);
    }

    public function test_client_owner_can_reject_final_quote(): void
    {
        [$client, $providerUser, $providerProfile, $strangerUser, $work] = $this->createWorkFixtures();

        $work->update(['final_price' => 28000]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote/reject");

        $response->assertStatus(200);
        $this->assertEquals(WorkStatus::Cancelled->value, $work->fresh()->status->value);
    }
}
