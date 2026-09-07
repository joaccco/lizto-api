<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationRoleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);

        // Ensure roles exist
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'provider', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_register_with_role_provider_payload_still_assigns_client_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Usuario Intento Provider',
            'email' => 'hacker_role_' . Str::random(5) . '@test.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'provider', // Payload tries to claim provider role
        ]);

        $response->assertStatus(201);

        $user = UserModel::where('email', $response->json('data.user.email'))->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('client'));
        $this->assertFalse($user->hasRole('provider'));
    }

    public function test_new_provider_profile_does_not_grant_provider_role(): void
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Aspirante',
            'email' => 'aspirante_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('client');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Draft,
            'bio' => 'Borrador de perfil',
        ]);

        $this->assertTrue($user->hasRole('client'));
        $this->assertFalse($user->fresh()->hasRole('provider'));
    }

    public function test_verifying_provider_grants_role_and_rejecting_or_suspending_revokes_it(): void
    {
        $admin = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Admin Test',
            'email' => 'admin_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Verificable',
            'email' => 'verificable_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('client');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::PendingVerification,
            'bio' => 'Perfil pendiente',
        ]);

        // 1. Verify -> Grants provider role
        $resVerify = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$profile->uuid}/verify");

        $resVerify->assertStatus(200);
        $this->assertTrue($user->fresh()->hasRole('provider'));

        // 2. Suspend -> Revokes provider role
        $resSuspend = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$profile->uuid}/suspend", [
                'reason' => 'Incumplimiento de términos',
            ]);

        $resSuspend->assertStatus(200);
        $this->assertFalse($user->fresh()->hasRole('provider'));

        // 3. Reactivate -> Grants provider role back
        $resReactivate = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$profile->uuid}/reactivate");

        $resReactivate->assertStatus(200);
        $this->assertTrue($user->fresh()->hasRole('provider'));

        // 4. Reject -> Revokes provider role
        $resReject = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$profile->uuid}/reject", [
                'reason' => 'Documentación inválida',
            ]);

        $resReject->assertStatus(200);
        $this->assertFalse($user->fresh()->hasRole('provider'));
    }

    public function test_existing_providers_retain_role_after_migration(): void
    {
        $existingProviderUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Preexistente',
            'email' => 'old_provider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $existingProviderUser->assignRole('provider');

        ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $existingProviderUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        // Run the preservation migration logic
        $this->artisan('migrate');

        $this->assertTrue($existingProviderUser->fresh()->hasRole('provider'));
    }
}
