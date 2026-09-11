<?php

namespace Tests\Feature;

use App\Infrastructure\KYC\IdentityProviderContract;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\AuditLog;
use App\Models\Identity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class IdentityConsentAuditTest extends TestCase
{
    use RefreshDatabase;

    protected $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockProvider = Mockery::mock(IdentityProviderContract::class);
        $this->app->instance(IdentityProviderContract::class, $this->mockProvider);
    }

    public function test_consent_is_recorded_in_audit_log_when_starting_verification_with_config_version(): void
    {
        config(['privacy.consent_version' => '1.0']);

        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario Consentimiento 1',
            'email' => 'consent1@test.com',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->mockProvider
            ->shouldReceive('createSession')
            ->once()
            ->andReturn([
                'session_id' => 'didit_sess_12345',
                'url' => 'https://verify.didit.me/session/12345',
            ]);

        $response = $this->postJson('/api/v1/onboarding/identity/start');
        $response->assertStatus(200);

        $identity = Identity::where('user_id', $user->id)->firstOrFail();

        $consentLog = AuditLog::where('event', 'identity_consent_recorded')
            ->where('subject_id', $identity->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($consentLog, 'No audit log was recorded for user consent');
        $this->assertEquals('consent_granted', $consentLog->action);
        $this->assertEquals('1.0', $consentLog->new_values['consent_version']);
        $this->assertNotNull($consentLog->new_values['consent_timestamp']);
        $this->assertNotNull($consentLog->created_at);
    }

    public function test_consent_records_updated_version_when_config_changes(): void
    {
        // Simular cambio de versión del texto legal en configuración
        config(['privacy.consent_version' => '2.5-legal-update-2026']);

        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario Consentimiento 2',
            'email' => 'consent2@test.com',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->mockProvider
            ->shouldReceive('createSession')
            ->once()
            ->andReturn([
                'session_id' => 'didit_sess_67890',
                'url' => 'https://verify.didit.me/session/67890',
            ]);

        $response = $this->postJson('/api/v1/onboarding/identity/start');
        $response->assertStatus(200);

        $identity = Identity::where('user_id', $user->id)->firstOrFail();

        $consentLog = AuditLog::where('event', 'identity_consent_recorded')
            ->where('subject_id', $identity->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($consentLog, 'No audit log was recorded for updated consent version');
        $this->assertEquals('2.5-legal-update-2026', $consentLog->new_values['consent_version']);
    }
}
