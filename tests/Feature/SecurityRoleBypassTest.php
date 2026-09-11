<?php

namespace Tests\Feature;

use App\Application\Matching\Actions\RunMatchingAction;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ProviderServiceAreaModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\Identity;
use App\Models\ProfessionalMVU;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityRoleBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_register_with_client_role_succeeds()
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'     => 'John Doe',
            'email'    => 'john@test.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'phone'    => '1234567890',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'john@test.com',
        ]);

        $user = UserModel::where('email', 'john@test.com')->first();
        $this->assertTrue($user->hasRole('client'));
        $this->assertFalse($user->hasRole('provider'));
    }

    public function test_become_provider_without_kyc_fails()
    {
        $user = UserModel::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $user->assignRole('client');

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $user->id,
            'professional_title' => 'Plumber',
            'availability_status' => 'available',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/become-provider');

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'Debes completar la verificación de identidad (KYC) antes de convertirte en proveedor.',
        ]);

        $user->refresh();
        $this->assertTrue($user->hasRole('client'));
        $this->assertFalse($user->hasRole('provider'));
    }

    public function test_become_provider_with_kyc_succeeds()
    {
        $user = UserModel::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $user->assignRole('client');

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $user->id,
            'professional_title' => 'Plumber',
            'availability_status' => 'available',
        ]);

        ProviderDocumentModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => $providerProfile->id,
            'document_type' => 'identity',
            'status' => 'approved',
            'file_path' => 'documents/kyc123.pdf',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/become-provider');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Ahora eres proveedor. ¡Bienvenido!',
            'role'    => 'provider',
        ]);

        $user->refresh();
        $this->assertTrue($user->hasRole('provider'));
    }

    public function test_register_password_must_have_uppercase_and_numbers()
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'     => 'John Doe',
            'email'    => 'john1@test.com',
            'password' => 'securepass123',
            'password_confirmation' => 'securepass123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_become_provider_with_pending_documents_fails_with_403()
    {
        $user = UserModel::create([
            'name' => 'Pending User',
            'email' => 'pending@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $user->assignRole('client');

        $profile = ProviderProfileModel::create([
            'user_id' => $user->id,
            'professional_title' => 'Plumber',
            'availability_status' => 'available',
        ]);

        ProviderDocumentModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => $profile->id,
            'document_type' => 'identity',
            'status' => 'pending',
            'file_path' => 'documents/pending.pdf',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/auth/become-provider');
        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasRole('provider'));
    }

    public function test_become_provider_with_rejected_documents_fails_with_403()
    {
        $user = UserModel::create([
            'name' => 'Rejected User',
            'email' => 'rejected@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $user->assignRole('client');

        $profile = ProviderProfileModel::create([
            'user_id' => $user->id,
            'professional_title' => 'Electrician',
            'availability_status' => 'available',
        ]);

        ProviderDocumentModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => $profile->id,
            'document_type' => 'identity',
            'status' => 'rejected',
            'rejection_reason' => 'Foto borrosa',
            'file_path' => 'documents/rejected.pdf',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/auth/become-provider');
        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasRole('provider'));
    }

    public function test_become_provider_with_verified_status_succeeds()
    {
        $user = UserModel::create([
            'name' => 'Verified KYC User',
            'email' => 'verified_kyc@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $user->assignRole('client');

        $profile = ProviderProfileModel::create([
            'user_id' => $user->id,
            'professional_title' => 'Carpenter',
            'availability_status' => 'available',
        ]);

        ProviderDocumentModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => $profile->id,
            'document_type' => 'identity',
            'status' => 'verified',
            'verified_at' => now(),
            'file_path' => 'documents/verified.pdf',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/auth/become-provider');
        $response->assertStatus(200);
        $this->assertTrue($user->fresh()->hasRole('provider'));
    }

    public function test_adversarial_race_condition_simulation_on_role_assignment()
    {
        $user = UserModel::create([
            'name' => 'Race User',
            'email' => 'race@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $user->assignRole('client');

        $profile = ProviderProfileModel::create([
            'user_id' => $user->id,
            'professional_title' => 'Plumber',
            'availability_status' => 'available',
        ]);

        ProviderDocumentModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => $profile->id,
            'document_type' => 'identity',
            'status' => 'verified',
            'verified_at' => now(),
            'file_path' => 'documents/race.pdf',
        ]);

        // First call assigns role
        $res1 = $this->actingAs($user)->postJson('/api/v1/auth/become-provider');
        $res1->assertStatus(200);

        // Immediate subsequent concurrent call handles idempotency safely
        $res2 = $this->actingAs($user)->postJson('/api/v1/auth/become-provider');
        $res2->assertStatus(200);
        $res2->assertJsonFragment(['message' => 'Ya eres proveedor.']);

        $this->assertTrue($user->fresh()->hasRole('provider'));
    }

    public function test_failed_role_escalation_attempts_are_logged()
    {
        \Illuminate\Support\Facades\Log::spy();

        $user = UserModel::create([
            'name' => 'Log Hacker',
            'email' => 'loghacker@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $user->assignRole('client');

        $this->actingAs($user)->postJson('/api/v1/auth/become-provider');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->atLeast()->once();
    }

    public function test_adversarial_legacy_flags_without_approved_mvu_excluded_from_search_and_matching()
    {
        $category = CategoryModel::firstOrCreate(
            ['slug' => 'cerrajeria'],
            ['name' => 'Cerrajería', 'icon' => 'lock', 'is_active' => true, 'sort_order' => 1]
        );

        // Proveedor con flags heredados en TRUE pero SIN MVU aprobado
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Bypass Attempt Provider',
            'email' => 'bypass@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);
        $user->assignRole('provider');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Verified, // Flag heredado = verified
            'is_verified' => true,                       // Flag heredado = true
            'availability_status' => 'available',
            'base_lat' => -27.4692,
            'base_lng' => -58.8306,
            'base_address' => 'Corrientes',
            'years_experience' => 5,
            'avg_rating' => 5.0,
            'total_reviews' => 10,
        ]);

        ProviderCategoryModel::create([
            'provider_id' => $profile->id,
            'category_id' => $category->id,
            'specialties' => ['apertura'],
            'price_type' => 'fixed',
            'is_active' => true,
        ]);

        ProviderServiceAreaModel::create([
            'provider_id' => $profile->id,
            'label' => 'Corrientes',
            'center_lat' => -27.4692,
            'center_lng' => -58.8306,
            'radius_km' => 20,
        ]);

        // Caso 1: Sin registro en professional_mvus
        $responseSearch = $this->getJson('/api/v1/providers');
        $responseSearch->assertStatus(200);
        $ids = collect($responseSearch->json('data'))->pluck('id')->all();
        $this->assertNotContains($profile->uuid, $ids, 'Proveedor con flags heredados sin MVU no debe aparecer en búsqueda pública.');

        // Caso 2: Con MVU pero en estado pending
        $mvu = ProfessionalMVU::create([
            'provider_id' => $profile->id,
            'overall_verification_status' => 'pending',
        ]);

        $responseSearch2 = $this->getJson('/api/v1/providers');
        $ids2 = collect($responseSearch2->json('data'))->pluck('id')->all();
        $this->assertNotContains($profile->uuid, $ids2, 'Proveedor con MVU pending no debe aparecer en búsqueda pública.');

        // Verificar en matching
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Client',
            'email' => 'client_match@test.com',
            'password' => bcrypt('SecurePass123'),
            'status' => 'active',
        ]);

        $request = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Urgente cerrajero',
            'urgency' => 'immediate',
            'status' => \App\Domain\ServiceRequests\Enums\RequestStatus::MatchingActive,
            'location_lat' => -27.4692,
            'location_lng' => -58.8306,
            'location_address' => 'Corrientes',
        ]);

        $action = new RunMatchingAction();
        $candidates = $action->execute($request);
        $candidateProviderIds = collect($candidates)->pluck('provider.id')->all();
        $this->assertNotContains($profile->id, $candidateProviderIds, 'Proveedor con MVU pending no debe ser candidato de matching.');

        // Caso 3: MVU pasa a approved -> AHORA SÍ debe aparecer
        $mvu->update(['overall_verification_status' => 'approved']);

        $responseSearch3 = $this->getJson('/api/v1/providers');
        $ids3 = collect($responseSearch3->json('data'))->pluck('id')->all();
        $this->assertContains($profile->uuid, $ids3, 'Proveedor con MVU approved debe aparecer en búsqueda.');

        $candidatesAfter = $action->execute($request);
        $candidateProviderIdsAfter = collect($candidatesAfter)->pluck('provider.id')->all();
        $this->assertContains($profile->id, $candidateProviderIdsAfter, 'Proveedor con MVU approved debe aparecer en matching.');
    }
}
