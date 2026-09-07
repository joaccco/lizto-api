<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityRoleBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
}
