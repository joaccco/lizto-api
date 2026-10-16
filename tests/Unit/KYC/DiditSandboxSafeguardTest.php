<?php

namespace Tests\Unit\KYC;

use App\Domain\Identity\Services\IdentityService;
use App\Infrastructure\KYC\DiditIdentityProvider;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiditSandboxSafeguardTest extends TestCase
{
    protected string $workflowId = '1a3cf8eb-1e92-4554-bb91-2017577cf811';

    protected function createTestUser(): UserModel
    {
        return new UserModel([
            'id' => 999,
            'uuid' => (string) Str::uuid(),
            'email' => 'provider_test@lizto.test',
        ]);
    }

    public function test_cannot_send_sandbox_scenario_without_explicit_sandbox_mode(): void
    {
        Http::fake();

        config([
            'services.didit.workflow_id' => $this->workflowId,
            'services.didit.sandbox_scenario' => 'approve',
            'services.didit.mode' => null, // Not explicitly set to sandbox
        ]);

        $service = app(IdentityService::class);
        $user = $this->createTestUser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Didit sandbox scenario 'approve' requires Didit mode to be explicitly configured as 'sandbox'");

        try {
            $service->initiateKYC($user);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cannot_send_sandbox_scenario_when_mode_is_live(): void
    {
        Http::fake();

        config([
            'services.didit.workflow_id' => $this->workflowId,
            'services.didit.sandbox_scenario' => 'decline_aml_hit',
            'services.didit.mode' => 'live',
        ]);

        $service = app(IdentityService::class);
        $user = $this->createTestUser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("cannot send sandbox scenario 'decline_aml_hit' when Didit mode is configured as 'live'");

        try {
            $service->initiateKYC($user);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cannot_send_sandbox_scenario_in_production_environment(): void
    {
        Http::fake();

        config([
            'services.didit.workflow_id' => $this->workflowId,
            'services.didit.sandbox_scenario' => 'review_aml_possible_match',
            'services.didit.mode' => 'sandbox',
            'app.env' => 'production',
        ]);
        App::detectEnvironment(fn () => 'production');

        $service = app(IdentityService::class);
        $user = $this->createTestUser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot use Didit sandbox scenario in production environment.');

        try {
            $service->initiateKYC($user);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_provider_direct_call_blocks_sandbox_scenario_without_sandbox_mode(): void
    {
        Http::fake();

        config(['app.env' => 'testing']);
        App::detectEnvironment(fn () => 'testing');

        // 'mode' => null hace que el provider caiga al config global, asi que
        // el config tambien tiene que estar sin modo. Si no, esta prueba pasa
        // o falla segun el .env de quien la corre.
        config(['services.didit.mode' => null]);

        $provider = new DiditIdentityProvider([
            'base_url' => 'https://verification.didit.me/v3',
            'api_key' => 'test-key',
            'mode' => null, // Not sandbox
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("requires Didit mode to be explicitly configured as 'sandbox'");

        try {
            $provider->createSession([
                'workflow_id' => $this->workflowId,
                'vendor_data' => 'test-vendor-uuid',
                'sandbox_scenario' => 'approve',
            ]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_provider_direct_call_blocks_sandbox_scenario_when_mode_is_live(): void
    {
        Http::fake();

        config(['app.env' => 'testing']);
        App::detectEnvironment(fn () => 'testing');

        $provider = new DiditIdentityProvider([
            'base_url' => 'https://verification.didit.me/v3',
            'api_key' => 'test-key',
            'mode' => 'live',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("when Didit mode is configured as 'live'");

        try {
            $provider->createSession([
                'workflow_id' => $this->workflowId,
                'vendor_data' => 'test-vendor-uuid',
                'sandbox_scenario' => 'decline_document_expired',
            ]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_sends_sandbox_scenario_when_properly_configured_in_sandbox_mode(): void
    {
        config([
            'app.env' => 'testing',
            'services.didit.workflow_id' => $this->workflowId,
            'services.didit.mode' => 'sandbox',
            'services.didit.sandbox_scenario' => 'decline_aml_hit',
        ]);
        App::detectEnvironment(fn () => 'testing');

        Http::fake([
            'https://verification.didit.me/v3/session/' => Http::response([
                'session_id' => 'sess_sandbox_123',
                'url' => 'https://verification.didit.me/verify/sess_sandbox_123',
                'status' => 'created',
            ], 201),
        ]);

        $provider = new DiditIdentityProvider([
            'base_url' => 'https://verification.didit.me/v3',
            'api_key' => 'test-key',
            'mode' => 'sandbox',
            'sandbox_scenario' => 'decline_aml_hit',
        ]);

        $res = $provider->createSession([
            'workflow_id' => $this->workflowId,
            'vendor_data' => 'vendor-uuid-789',
        ]);

        $this->assertEquals('sess_sandbox_123', $res['session_id']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://verification.didit.me/v3/session/'
                && $request['workflow_id'] === $this->workflowId
                && $request['sandbox_scenario'] === 'decline_aml_hit';
        });
    }

    public function test_default_configuration_does_not_send_sandbox_scenario(): void
    {
        config([
            'app.env' => 'testing',
            'services.didit.workflow_id' => $this->workflowId,
            'services.didit.mode' => null,
            'services.didit.sandbox_scenario' => null,
        ]);
        App::detectEnvironment(fn () => 'testing');

        Http::fake([
            'https://verification.didit.me/v3/session/' => Http::response([
                'session_id' => 'sess_normal_456',
                'url' => 'https://verification.didit.me/verify/sess_normal_456',
                'status' => 'created',
            ], 201),
        ]);

        $provider = new DiditIdentityProvider([
            'base_url' => 'https://verification.didit.me/v3',
            'api_key' => 'test-key',
        ]);

        $res = $provider->createSession([
            'workflow_id' => $this->workflowId,
            'vendor_data' => 'vendor-uuid-789',
        ]);

        $this->assertEquals('sess_normal_456', $res['session_id']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://verification.didit.me/v3/session/'
                && $request['workflow_id'] === $this->workflowId
                && !isset($request['sandbox_scenario']);
        });
    }
}
