<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConfirmWorkRequestTest extends TestCase
{
    use RefreshDatabase;

    private function createProviderSetup(): array
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Test',
            'email' => 'provider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('provider');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $profile->id,
            'overall_verification_status' => 'approved',
        ]);

        $category = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cerrajería',
            'slug' => 'cerrajeria',
        ]);

        $profile->categories()->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Test',
            'email' => 'client_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        return [$user, $profile, $category, $client];
    }

    public function test_confirm_work_request_without_preexisting_match_card_succeeds_without_422(): void
    {
        [$user, $profile, $category, $client] = $this->createProviderSetup();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Apertura de puerta de urgencia',
            'status' => 'pending_matching',
        ]);

        // POST /api/v1/provider/work-requests/{id}/confirm
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/provider/work-requests/{$sr->uuid}/confirm", [
                'estimated_duration_min' => 45,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Trabajo confirmado.');
        $response->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('works', [
            'service_request_id' => $sr->id,
            'provider_id' => $profile->id,
        ]);
    }

    public function test_confirming_already_confirmed_work_request_returns_200_idempotently(): void
    {
        [$user, $profile, $category, $client] = $this->createProviderSetup();

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de cerradura',
            'status' => 'provider_selected',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $profile->id,
            'status' => 'confirmed',
            'agreed_price' => 15000,
        ]);

        // Confirming again should return 200 idempotently
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/provider/work-requests/{$sr->uuid}/confirm");

        $response->assertStatus(200);
        $response->assertJsonPath('data.work_id', $work->uuid);
    }
}
