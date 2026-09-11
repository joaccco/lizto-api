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
        $canonical = DiditIdentityProvider::canonicalizeJson($rawPayload);
        return hash_hmac('sha256', "{$timestamp}:{$canonical}", $this->webhookSecret);
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
            'HTTP_X_SIGNATURE_V2' => 'fake_sig',
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
            'HTTP_X_SIGNATURE_V2' => 'tampered_signature_hex_1234',
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
            'HTTP_X_SIGNATURE_V2' => $sig,
            'HTTP_X_TIMESTAMP' => $expiredTimestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(401);
    }

    public function test_webhook_canonicalization_against_synthetic_fixture(): void
    {
        $fixtureSecret = 'whsec_didit_sandbox_verified_fixture_key';
        $realProvider = new DiditIdentityProvider(['webhook_secret' => $fixtureSecret]);
        $this->app->instance(IdentityProviderContract::class, $realProvider);

        // Cargar fixture sintético (nota: la firma no está validada contra Didit y el caso real sigue pendiente)
        $rawPayload = file_get_contents(base_path('tests/Fixtures/KYC/didit_webhook_synthetic.json'));
        $currentTimestamp = (string) time();

        // Firma canónica externa esperada sobre el payload sintético
        $expectedCanonicalString = '{"_disclaimer":"NOTA: Payload sintético generado para pruebas locales de canonicalización. La firma NO está validada contra Didit Sandbox ni Producción. La captura de un webhook real sigue pendiente de un túnel público.","event":"session.verified","session_id":"8b51c148-5fa0-482a-89a7-96a84c987fa2","status":"approved","timestamp":1725782400,"vendor_data":"f47ac10b-58cc-4372-a567-0e02b2c3d479","workflow_id":"1a3cf8eb-1e92-4554-bb91-2017577cf811"}';
        $literalSignature = hash_hmac('sha256', "{$currentTimestamp}:{$expectedCanonicalString}", $fixtureSecret);

        // Enviar payload sintético crudo desordenado; el controller y provider deben canonicalizarlo
        // y validar contra la firma esperada externa fija
        $response = $this->call('POST', '/api/v1/webhooks/didit/identity-verified', [], [], [], [
            'HTTP_X_SIGNATURE_V2' => $literalSignature,
            'HTTP_X_TIMESTAMP' => $currentTimestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $rawPayload);

        // No debe retornar 401 Unauthorized
        $this->assertNotEquals(401, $response->getStatusCode(), 'La canonicalización del payload sintético debe coincidir con la firma esperada.');
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
            'HTTP_X_SIGNATURE_V2' => 'valid_sig',
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
            'HTTP_X_SIGNATURE_V2' => 'valid_sig',
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
            'HTTP_X_SIGNATURE_V2' => 'valid_sig',
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
            'HTTP_X_SIGNATURE_V2' => 'valid_sig',
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
