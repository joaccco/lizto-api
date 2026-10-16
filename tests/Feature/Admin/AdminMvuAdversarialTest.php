<?php

namespace Tests\Feature\Admin;

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\AuditLog;
use App\Models\ProfessionalMVU;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminMvuAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $admin;
    protected UserModel $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Admin Test',
            'email' => 'admin_adversarial@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');

        $this->client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Client Test',
            'email' => 'client_adversarial@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $this->client->assignRole('client');
    }

    protected function createPendingMVU(): ProfessionalMVU
    {
        $providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Test',
            'email' => 'provider_mvu_adv@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $providerUser->assignRole('provider');

        $provider = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $providerUser->id,
            'status' => 'pending_verification',
            'is_verified' => false,
        ]);

        return ProfessionalMVU::create([
            'provider_id' => $provider->id,
            'overall_verification_status' => 'pending',
            'antecedentes_status' => 'pending',
            'skills_verified' => false,
        ]);
    }

    public function test_admin_user_seeder_strictly_prohibited_in_production(): void
    {
        config(['app.env' => 'production']);
        App::detectEnvironment(fn () => 'production');

        $seeder = new AdminUserSeeder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AdminUserSeeder is strictly prohibited in production environment.');

        try {
            $seeder->run();
        } finally {
            $this->assertDatabaseMissing('users', [
                'email' => 'admin@lizto.test',
            ]);
            // Restore testing environment
            config(['app.env' => 'testing']);
            App::detectEnvironment(fn () => 'testing');
        }
    }

    public function test_admin_user_seeder_is_idempotent_and_does_not_duplicate_admin(): void
    {
        config(['app.env' => 'testing']);
        App::detectEnvironment(fn () => 'testing');

        $seeder = new AdminUserSeeder();

        // Run twice
        $seeder->run();
        $seeder->run();

        $this->assertEquals(1, UserModel::where('email', 'admin@lizto.test')->count());

        $admin = UserModel::where('email', 'admin@lizto.test')->first();
        $this->assertNotNull($admin);
        $this->assertEquals('Admin Lizto', $admin->name);
        $this->assertEquals('active', $admin->status instanceof \BackedEnum ? $admin->status->value : (string) $admin->status);
        $this->assertTrue($admin->hasRole('admin'));
    }

    public function test_client_role_cannot_access_mvu_show_endpoint(): void
    {
        $mvu = $this->createPendingMVU();
        Sanctum::actingAs($this->client);

        $response = $this->getJson("/api/v1/admin/mvu/{$mvu->id}");

        $response->assertStatus(403);
    }

    public function test_client_role_cannot_approve_mvu(): void
    {
        $mvu = $this->createPendingMVU();
        Sanctum::actingAs($this->client);

        $response = $this->postJson("/api/v1/admin/mvu/{$mvu->id}/approve");

        $response->assertStatus(403);

        $mvu->refresh();
        $this->assertEquals('pending', $mvu->overall_verification_status);
    }

    public function test_client_role_cannot_reject_mvu(): void
    {
        $mvu = $this->createPendingMVU();
        Sanctum::actingAs($this->client);

        $response = $this->postJson("/api/v1/admin/mvu/{$mvu->id}/reject", [
            'reason' => 'Intento de rechazo no autorizado',
        ]);

        $response->assertStatus(403);

        $mvu->refresh();
        $this->assertEquals('pending', $mvu->overall_verification_status);
    }

    public function test_client_role_cannot_request_data_from_mvu(): void
    {
        $mvu = $this->createPendingMVU();
        Sanctum::actingAs($this->client);

        $response = $this->postJson("/api/v1/admin/mvu/{$mvu->id}/request-data", [
            'notes' => 'Notas no autorizadas',
        ]);

        $response->assertStatus(403);
    }

    public function test_approve_changes_overall_verification_status_to_approved(): void
    {
        $mvu = $this->createPendingMVU();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/admin/mvu/{$mvu->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('data.overall_verification_status', 'approved');

        $mvu->refresh();
        $provider = $mvu->provider;
        $this->assertEquals('approved', $mvu->overall_verification_status);
        $this->assertTrue($mvu->skills_verified);
        $this->assertTrue($provider->is_verified);
        $this->assertEquals('verified', $provider->status->value);
    }

    public function test_reject_changes_overall_verification_status_to_rejected(): void
    {
        $mvu = $this->createPendingMVU();
        Sanctum::actingAs($this->admin);

        $reason = 'Certificado de antecedentes apócrifo detectado en revisión manual.';
        $response = $this->postJson("/api/v1/admin/mvu/{$mvu->id}/reject", [
            'reason' => $reason,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.overall_verification_status', 'rejected');

        $mvu->refresh();
        $provider = $mvu->provider;
        $this->assertEquals('rejected', $mvu->overall_verification_status);
        $this->assertFalse($provider->is_verified);
        $this->assertEquals('rejected', $provider->status->value);
        $this->assertEquals($reason, $provider->rejection_reason);
    }

    public function test_request_data_creates_audit_log_without_changing_status(): void
    {
        $mvu = $this->createPendingMVU();
        Sanctum::actingAs($this->admin);

        $notes = 'Por favor subir certificado de antecedentes con firma digital válida de RNR.';
        $response = $this->postJson("/api/v1/admin/mvu/{$mvu->id}/request-data", [
            'notes' => $notes,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Solicitud de datos enviada al profesional.');

        $mvu->refresh();
        $this->assertEquals('pending', $mvu->overall_verification_status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'mvu_data_requested',
            'subject_type' => 'ProfessionalMVU',
            'subject_id' => $mvu->id,
            'user_id' => $this->admin->id,
            'action' => 'request_data',
        ]);
    }
}
