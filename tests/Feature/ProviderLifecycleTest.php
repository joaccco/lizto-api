<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\RatingModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderLifecycleTest extends TestCase
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
            'name' => 'Usuario Test ' . rand(100, 999),
            'email' => 'user_' . Str::random(8) . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_user_can_start_provider_onboarding_in_draft_status(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/provider/profile', [
                'first_name' => 'Roberto',
                'last_name' => 'Medina',
                'commercial_name' => 'Cerrajería Medina PRO',
                'bio' => 'Cerrajero experto con 10 años de experiencia.',
                'base_address' => 'Av. Corrientes 1240, CABA',
                'years_experience' => 10,
                'radius_km' => 20,
            ]);

        $response->assertStatus(200);

        $profile = ProviderProfileModel::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals(ProviderProfileStatus::Draft, $profile->status);
        $this->assertEquals('Cerrajería Medina PRO', $profile->commercial_name);
    }

    public function test_provider_can_upload_private_verification_document(): void
    {
        $user = $this->createUser();
        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Draft,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/provider/profile/documents', [
                'document_type' => 'dni_front',
                'document_number' => '35123456',
                'file_path' => '/storage/verification_docs/dni_front.jpg',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('provider_documents', [
            'provider_id' => $profile->id,
            'document_type' => 'dni_front',
            'document_number' => '35123456',
        ]);
    }

    public function test_provider_can_submit_profile_for_verification(): void
    {
        $user = $this->createUser();
        $category = CategoryModel::first();

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Draft,
            'base_address' => 'Thames 1842, Palermo',
        ]);

        $profile->categories()->create([
            'category_id' => $category->id,
            'specialties' => ['Aperturas', 'Cerraduras de seguridad'],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/provider/profile/submit-verification');

        $response->assertStatus(200);

        $profile->refresh();
        $this->assertEquals(ProviderProfileStatus::PendingVerification, $profile->status);
        $this->assertNotNull($profile->submitted_at);
    }

    public function test_admin_can_verify_reject_and_suspend_provider(): void
    {
        $admin = $this->createUser('admin');
        $admin->assignRole('admin');

        $providerUser = $this->createUser('provider');
        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::PendingVerification,
            'submitted_at' => now(),
        ]);

        // 1. Admin Verifies
        $verifyRes = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$profile->id}/verify");

        $verifyRes->assertStatus(200);
        $profile->refresh();
        $this->assertEquals(ProviderProfileStatus::Verified, $profile->status);
        $this->assertTrue($profile->is_verified);
        $this->assertNotNull($profile->verified_at);

        // 2. Admin Rejects
        $rejectRes = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$profile->id}/reject", [
                'reason' => 'Documentación legible requerida.',
            ]);

        $rejectRes->assertStatus(200);
        $profile->refresh();
        $this->assertEquals(ProviderProfileStatus::Rejected, $profile->status);
        $this->assertFalse($profile->is_verified);
        $this->assertEquals('Documentación legible requerida.', $profile->rejection_reason);

        // 3. Admin Suspends
        $suspendRes = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$profile->id}/suspend", [
                'reason' => 'Incumplimiento de términos de servicio.',
            ]);

        $suspendRes->assertStatus(200);
        $profile->refresh();
        $this->assertEquals(ProviderProfileStatus::Suspended, $profile->status);
        $this->assertFalse($profile->is_verified);
    }

    public function test_public_profile_endpoint_does_not_expose_private_documents(): void
    {
        $providerUser = $this->createUser('provider');
        $providerUser->update(['name' => 'Ana Kupfer']);

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'commercial_name' => 'Cerrajería Ana',
            'bio' => 'Cerrajera matriculada.',
        ]);

        ProviderDocumentModel::create([
            'uuid' => (string) Str::uuid(),
            'provider_id' => $profile->id,
            'document_type' => 'dni_front',
            'document_number' => '99887766',
            'file_path' => '/storage/secret/dni.png',
        ]);

        $clientUser = $this->createUser('client');

        $response = $this->actingAs($clientUser, 'sanctum')
            ->getJson("/api/v1/providers/{$profile->uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Cerrajería Ana');
        $response->assertJsonMissing(['document_number' => '99887766']);
        $response->assertJsonMissing(['file_path' => '/storage/secret/dni.png']);
    }

    public function test_public_profile_returns_real_reviews_and_formatted_client_name(): void
    {
        $providerUser = $this->createUser('provider');
        $providerUser->update(['name' => 'Roberto Medina']);

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'commercial_name' => 'Cerrajería Medina',
            'avg_rating' => 5.0,
            'total_reviews' => 1,
        ]);

        $clientUser = $this->createUser('client');
        $clientUser->update(['name' => 'María García']);

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $clientUser->id,
            'raw_prompt' => 'Apertura de puerta',
            'status' => 'completed',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $clientUser->id,
            'provider_id' => $profile->id,
            'status' => \App\Domain\Works\Enums\WorkStatus::Completed,
        ]);

        RatingModel::create([
            'work_id' => $work->id,
            'reviewer_id' => $clientUser->id,
            'reviewed_id' => $providerUser->id,
            'direction' => 'client_to_provider',
            'score' => 5,
            'comment' => 'Excelente servicio, muy puntual.',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/providers/{$profile->uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.reviews.0.score', 5);
        $response->assertJsonPath('data.reviews.0.comment', 'Excelente servicio, muy puntual.');
        $response->assertJsonPath('data.reviews.0.reviewer_name', 'María G.');
    }

    public function test_provider_reviews_paginated_endpoint(): void
    {
        $providerUser = $this->createUser('provider');
        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        $clientUser = $this->createUser('client');
        $clientUser->update(['name' => 'Juan Pérez']);

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $clientUser->id,
            'raw_prompt' => 'Reparación de cerradura',
            'status' => 'completed',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $clientUser->id,
            'provider_id' => $profile->id,
            'status' => \App\Domain\Works\Enums\WorkStatus::Completed,
        ]);

        RatingModel::create([
            'work_id' => $work->id,
            'reviewer_id' => $clientUser->id,
            'reviewed_id' => $providerUser->id,
            'direction' => 'client_to_provider',
            'score' => 5,
            'comment' => 'Todo perfecto.',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/providers/{$profile->uuid}/reviews");

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.reviewer_name', 'Juan P.');
        $response->assertJsonPath('meta.total', 1);
    }
}
