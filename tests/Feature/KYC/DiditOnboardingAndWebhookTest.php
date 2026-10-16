<?php

namespace Tests\Feature\KYC;

use App\Domain\Professional\Services\ProfessionalRequirementsEvaluator;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Trust\Services\BanService;
use App\Infrastructure\KYC\DiditIdentityProvider;
use App\Infrastructure\KYC\IdentityProviderContract;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ProviderServiceAreaModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\ProfessionalMVU;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class DiditOnboardingAndWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected $mockProvider;
    protected string $webhookSecret = 'whsec_adversarial_test_secret_9988';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.didit.webhook_secret' => $this->webhookSecret]);
        config(['services.didit.api_key' => 'test_api_key']);

        $this->mockProvider = Mockery::mock(IdentityProviderContract::class);
        $this->app->instance(IdentityProviderContract::class, $this->mockProvider);
    }

    protected function computeSignature(string $rawPayload, string $timestamp): string
    {
        // Didit firma el cuerpo crudo con HMAC-SHA256; el timestamp solo
        // acota la ventana de replay, no entra en la cadena firmada.
        return hash_hmac('sha256', $rawPayload, $this->webhookSecret);
    }

    public function test_onboarding_identity_start_unauthenticated_returns_401(): void
    {
        $response = $this->postJson('/api/v1/onboarding/identity/start');
        $response->assertStatus(401);
    }

    public function test_onboarding_identity_start_authenticated_success(): void
    {
        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'John Doe',
            'email' => 'john@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->mockProvider
            ->shouldReceive('createSession')
            ->once()
            ->withArgs(function ($params) use ($user) {
                return $params['vendor_data'] === (string) $user->uuid
                    && !empty($params['workflow_id']);
            })
            ->andReturn([
                'session_id' => 'sess_didit_v3_123',
                'url' => 'https://verification.didit.me/verify/sess_didit_v3_123',
                'status' => 'created',
            ]);

        $response = $this->postJson('/api/v1/onboarding/identity/start');

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.session_id', 'sess_didit_v3_123');
        $response->assertJsonPath('data.url', 'https://verification.didit.me/verify/sess_didit_v3_123');

        $this->assertDatabaseHas('identities', [
            'user_id' => $user->id,
            'status' => 'pending',
            'didit_kyc_response_id' => 'sess_didit_v3_123',
        ]);
    }

    public function test_webhook_missing_signature_or_timestamp_returns_401(): void
    {
        $payload = json_encode(['session_id' => 'sess_123']);

        // Missing both
        $res1 = $this->postJson('/api/v1/webhooks/didit/identity-verified', ['session_id' => 'sess_123']);
        $res1->assertStatus(401);

        // Missing signature
        $res2 = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_TIMESTAMP' => (string) time(),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        $res2->assertStatus(401);

        // Missing timestamp
        $res3 = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => 'fake_sig',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        $res3->assertStatus(401);
    }

    public function test_webhook_invalid_signature_returns_401(): void
    {
        $realProvider = new DiditIdentityProvider(['webhook_secret' => $this->webhookSecret]);
        $this->app->instance(IdentityProviderContract::class, $realProvider);

        $payload = json_encode(['session_id' => 'sess_123']);
        $timestamp = (string) time();

        $response = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => 'tampered_signature_hex_1234',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Invalid signature or expired timestamp.');
    }

    public function test_webhook_expired_timestamp_returns_401_replay_protection(): void
    {
        $realProvider = new DiditIdentityProvider(['webhook_secret' => $this->webhookSecret]);
        $this->app->instance(IdentityProviderContract::class, $realProvider);

        $payload = json_encode(['session_id' => 'sess_123']);
        // 10 minutes in the past
        $expiredTimestamp = (string) (time() - 600);
        $sig = $this->computeSignature($payload, $expiredTimestamp);

        $response = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => $sig,
            'HTTP_X_TIMESTAMP' => $expiredTimestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(401);
    }

    public function test_webhook_accepts_real_didit_payload(): void
    {
        // Cuerpo CRUDO byte a byte de una entrega real de Didit sandbox
        // (sesion 68d3a20e-d1f6-4580-8142-351e1e1b0a3b, entrega 660599ab, 2026-10-12).
        // Reemplaza al fixture sintetico anterior, cuya firma la habia fabricado
        // el agente y nunca se valido contra Didit.
        $rawPayload = file_get_contents(base_path('tests/Fixtures/KYC/didit_webhook_real_body.json'));

        $realProvider = new DiditIdentityProvider(['webhook_secret' => $this->webhookSecret]);
        $this->app->instance(IdentityProviderContract::class, $realProvider);
        Http::fake();

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $rawPayload, $this->webhookSecret);

        $response = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $rawPayload);

        $this->assertNotEquals(401, $response->getStatusCode(), 'La firma HMAC sobre el cuerpo crudo real de Didit debe validar.');
    }

    public function test_webhook_signature_over_canonicalized_body_is_rejected(): void
    {
        // Regresion: el esquema anterior firmaba "timestamp:json_canonico".
        // Didit firma el cuerpo crudo, asi que ese esquema debe fallar.
        $rawPayload = file_get_contents(base_path('tests/Fixtures/KYC/didit_webhook_real_body.json'));

        $realProvider = new DiditIdentityProvider(['webhook_secret' => $this->webhookSecret]);
        $this->app->instance(IdentityProviderContract::class, $realProvider);

        $timestamp = (string) time();
        $firmaEsquemaViejo = hash_hmac('sha256', "{$timestamp}:{$rawPayload}", $this->webhookSecret);

        $response = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => $firmaEsquemaViejo,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $rawPayload);

        $response->assertStatus(401);
    }

    public function test_webhook_with_non_uuid_vendor_data_returns_200_not_500(): void
    {
        // Regresion de un 500 real (12-10): users.uuid es de tipo uuid en
        // Postgres, y consultarla con texto que no es un UUID lanzaba
        // SQLSTATE[22P02]. Didit reintenta los 500 para siempre, asi que un
        // solo vendor_data mal formado generaba un bucle de reintentos.
        $realProvider = Mockery::mock(IdentityProviderContract::class);
        $this->app->instance(IdentityProviderContract::class, $realProvider);

        $realProvider->shouldReceive('verifyWebhookSignature')->andReturn(true);
        $realProvider
            ->shouldReceive('getDecision')
            ->once()
            ->with('sess_vendor_data_invalido')
            ->andReturn([
                'session_id' => 'sess_vendor_data_invalido',
                'status' => 'Approved',
                'vendor_data' => 'lizto-prueba-webhook-2', // no es un UUID
            ]);

        $payload = json_encode(['session_id' => 'sess_vendor_data_invalido']);

        $response = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => 'valid_sig',
            'HTTP_X_TIMESTAMP' => (string) time(),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'not_found');
    }

    public function test_webhook_valid_signature_fetches_decision_and_approves_identity_and_is_idempotent(): void
    {
        $realProvider = Mockery::mock(IdentityProviderContract::class);
        $this->app->instance(IdentityProviderContract::class, $realProvider);

        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Webhook User',
            'email' => 'webhook_user@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $identity = Identity::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'didit_kyc_response_id' => 'sess_webhook_999',
        ]);

        $payload = json_encode(['session_id' => 'sess_webhook_999']);
        $timestamp = (string) time();

        $realProvider
            ->shouldReceive('verifyWebhookSignature')
            ->andReturn(true);

        $realProvider
            ->shouldReceive('getDecision')
            ->once()
            ->with('sess_webhook_999')
            ->andReturn([
                'session_id' => 'sess_webhook_999',
                'status' => 'approved',
                'decision' => 'PASS',
                'document' => [
                    'document_number' => '33999888',
                    'first_name' => 'Webhook',
                    'last_name' => 'User',
                    'birth_date' => '1992-04-10',
                ],
            ]);

        // First webhook delivery
        $res1 = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => 'valid_sig',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $res1->assertStatus(200);
        $res1->assertJsonPath('status', 'approved');

        $identity->refresh();
        $this->assertEquals('approved', $identity->status);
        $this->assertEquals('33999888', $identity->dni);
        $this->assertNotNull($identity->verified_at);

        $auditCount = AuditLog::where('subject_type', 'Identity')
            ->where('subject_id', $identity->id)
            ->where('action', 'approve')
            ->count();
        $this->assertEquals(1, $auditCount, 'Exactly 1 audit log should exist for identity approval');

        // Second delivery (duplicate / retry) -> Idempotency
        $res2 = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => 'valid_sig',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $res2->assertStatus(200);
        $res2->assertJsonPath('status', 'already_processed');

        // Verify audit log was NOT duplicated
        $auditCountAfter = AuditLog::where('subject_type', 'Identity')
            ->where('subject_id', $identity->id)
            ->where('action', 'approve')
            ->count();
        $this->assertEquals(1, $auditCountAfter, 'Duplicate webhook must not produce duplicate audit logs');
    }

    public function test_decision_with_duplicate_dni_rejects_identity_and_creates_audit_alert(): void
    {
        $realProvider = Mockery::mock(IdentityProviderContract::class);
        $this->app->instance(IdentityProviderContract::class, $realProvider);

        // Existing approved user with DNI 38111222
        $user1 = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Legitimate User',
            'email' => 'legit@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        Identity::create([
            'user_id' => $user1->id,
            'dni' => '38111222',
            'status' => 'approved',
            'verified_at' => now(),
            'verified_by' => 'didit_id',
        ]);

        // Second user attempts verification
        $user2 = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Impostor User',
            'email' => 'impostor@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $identity2 = Identity::create([
            'user_id' => $user2->id,
            'status' => 'pending',
            'didit_kyc_response_id' => 'sess_impostor_dup',
        ]);

        $realProvider
            ->shouldReceive('verifyWebhookSignature')
            ->andReturn(true);

        $realProvider
            ->shouldReceive('getDecision')
            ->once()
            ->with('sess_impostor_dup')
            ->andReturn([
                'session_id' => 'sess_impostor_dup',
                'status' => 'approved',
                'decision' => 'PASS',
                'document' => [
                    'document_number' => '38111222', // DUPLICATE DNI!
                    'first_name' => 'Impostor',
                    'last_name' => 'User',
                ],
            ]);

        $payload = json_encode(['session_id' => 'sess_impostor_dup']);
        $timestamp = (string) time();

        $response = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => 'valid_sig',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'rejected');
        $response->assertJsonPath('errors.dni', 'duplicate_detected');

        $identity2->refresh();
        $this->assertEquals('rejected', $identity2->status);

        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => 'Identity',
            'subject_id' => $identity2->id,
            'action' => 'reject',
        ]);
    }

    public function test_regulated_category_electrician_without_matricula_remains_pending_mvu(): void
    {
        $realProvider = Mockery::mock(IdentityProviderContract::class);
        $this->app->instance(IdentityProviderContract::class, $realProvider);

        $category = CategoryModel::firstOrCreate(
            ['slug' => 'electricidad'],
            ['name' => 'Electricidad', 'icon' => 'zap', 'is_active' => true, 'sort_order' => 2]
        );

        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Carlos Electrician',
            'email' => 'carlos.elec@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $user->assignRole('provider');

        $profile = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::PendingVerification,
            'is_verified' => false,
            'availability_status' => 'available',
            'base_lat' => -27.4692,
            'base_lng' => -58.8306,
            'years_experience' => 4,
        ]);

        ProviderCategoryModel::create([
            'provider_id' => $profile->id,
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $identity = Identity::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'didit_kyc_response_id' => 'sess_elec_123',
        ]);

        $realProvider
            ->shouldReceive('verifyWebhookSignature')
            ->andReturn(true);

        // Didit returns PASS for Identity (OCR + Face)
        $realProvider
            ->shouldReceive('getDecision')
            ->once()
            ->with('sess_elec_123')
            ->andReturn([
                'session_id' => 'sess_elec_123',
                'status' => 'approved',
                'decision' => 'PASS',
                'document' => [
                    'document_number' => '32111444',
                    'first_name' => 'Carlos',
                    'last_name' => 'Electrician',
                ],
            ]);

        $payload = json_encode(['session_id' => 'sess_elec_123']);
        $timestamp = (string) time();

        $response = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE' => 'valid_sig',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'approved');

        // Identity is approved
        $identity->refresh();
        $this->assertEquals('approved', $identity->status);

        // BUT MVU remains pending because electrician requires matrícula and antecedentes!
        $mvu = ProfessionalMVU::where('provider_id', $profile->id)->first();
        $this->assertNotNull($mvu);
        $this->assertEquals('pending', $mvu->overall_verification_status, 'Electrician without matrícula must remain pending');

        // Provider must NOT appear in search because MVU is not approved!
        $searchRes = $this->getJson('/api/v1/providers');
        $ids = collect($searchRes->json('data'))->pluck('id')->all();
        $this->assertNotContains($profile->uuid, $ids, 'Electrician with pending MVU must not appear in search');
    }

    public function test_onboarding_identity_status_endpoint(): void
    {
        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Status User',
            'email' => 'status_user@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        // Not started
        $res1 = $this->getJson('/api/v1/onboarding/identity/status');
        $res1->assertStatus(200);
        $res1->assertJsonPath('status', 'not_started');

        // Started
        Identity::create([
            'user_id' => $user->id,
            'status' => 'approved',
            'verified_at' => now(),
            'verified_by' => 'didit_id',
        ]);

        $res2 = $this->getJson('/api/v1/onboarding/identity/status');
        $res2->assertStatus(200);
        $res2->assertJsonPath('status', 'approved');
    }

    public function test_banned_or_restricted_provider_cannot_access_work_requests(): void
    {
        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Provider Trust',
            'email' => 'provider_trust@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => 'verified',
            'is_verified' => true,
            'availability_status' => 'available',
        ]);

        Sanctum::actingAs($user);

        // Normally accessible
        $resNormal = $this->getJson('/api/v1/provider/work-requests');
        $resNormal->assertStatus(200);

        // Add restriction 'cannot_accept_requests'
        app(BanService::class)->restrict(
            $user,
            'cannot_accept_requests',
            'Temporary penalty',
            now()->addDays(3)
        );

        $resRestricted = $this->getJson('/api/v1/provider/work-requests');
        $resRestricted->assertStatus(403);
        $resRestricted->assertJsonPath('message', 'Tu cuenta tiene una restricción activa para recibir solicitudes.');

        // Ban user
        app(BanService::class)->ban($user, 'fraud_confirmed', 'Suspicious activity');
        $user->refresh();

        $resBanned = $this->getJson('/api/v1/provider/work-requests');
        $resBanned->assertStatus(403);
        $resBanned->assertJsonPath('message', 'Tu cuenta se encuentra suspendida.');
    }
}
