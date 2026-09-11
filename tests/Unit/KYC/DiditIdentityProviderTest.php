<?php

namespace Tests\Unit\KYC;

use App\Domain\Identity\Exceptions\DiditApiException;
use App\Infrastructure\KYC\DiditIdentityProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiditIdentityProviderTest extends TestCase
{
    protected DiditIdentityProvider $provider;
    protected string $secret = 'whsec_test_secret_1234567890abcdef';
    protected string $apiKey = 'test_api_key_xyz';

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new DiditIdentityProvider([
            'base_url' => 'https://verification.didit.me/v3',
            'api_key' => $this->apiKey,
            'webhook_secret' => $this->secret,
            'timeout' => 5,
        ]);
    }

    public function test_create_session_success(): void
    {
        Http::fake([
            'https://verification.didit.me/v3/session/' => Http::response([
                'session_id' => 'sess_v3_abc123',
                'url' => 'https://verification.didit.me/verify/sess_v3_abc123',
                'status' => 'created',
                'session_token' => 'tok_abc123',
            ], 201),
        ]);

        $res = $this->provider->createSession([
            'workflow_id' => '1a3cf8eb-1e92-4554-bb91-2017577cf811',
            'vendor_data' => 'user-uuid-1234',
            'callback' => 'https://lizto.test/callback',
        ]);

        $this->assertEquals('sess_v3_abc123', $res['session_id']);
        $this->assertEquals('https://verification.didit.me/verify/sess_v3_abc123', $res['url']);
        $this->assertEquals('created', $res['status']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://verification.didit.me/v3/session/'
                && $request->hasHeader('x-api-key', $this->apiKey)
                && $request['workflow_id'] === '1a3cf8eb-1e92-4554-bb91-2017577cf811'
                && $request['vendor_data'] === 'user-uuid-1234';
        });
    }

    public function test_create_session_throws_when_required_params_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider->createSession(['vendor_data' => 'user-uuid-1234']); // Missing workflow_id
    }

    public function test_create_session_throws_didit_api_exception_on_failure(): void
    {
        Http::fake([
            'https://verification.didit.me/v3/session/' => Http::response([
                'error' => 'invalid_workflow',
                'message' => 'Workflow not found',
            ], 404),
        ]);

        try {
            $this->provider->createSession([
                'workflow_id' => 'non-existent-uuid',
                'vendor_data' => 'user-uuid-1234',
            ]);
            $this->fail('Expected DiditApiException was not thrown.');
        } catch (DiditApiException $e) {
            $this->assertEquals(404, $e->getStatusCode());
            $this->assertStringContainsString('Workflow not found', $e->getResponseBody());
        }
    }

    public function test_get_decision_success(): void
    {
        Http::fake([
            'https://verification.didit.me/v3/session/sess_xyz/decision/' => Http::response([
                'session_id' => 'sess_xyz',
                'status' => 'approved',
                'decision' => 'PASS',
                'document' => [
                    'document_number' => '35123456',
                    'first_name' => 'Juan',
                    'last_name' => 'Pérez',
                    'birth_date' => '1990-05-15',
                ],
            ], 200),
        ]);

        $res = $this->provider->getDecision('sess_xyz');

        $this->assertEquals('approved', $res['status']);
        $this->assertEquals('PASS', $res['decision']);
        $this->assertEquals('35123456', $res['document']['document_number']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://verification.didit.me/v3/session/sess_xyz/decision/'
                && $request->hasHeader('x-api-key', $this->apiKey);
        });
    }

    public function test_get_decision_throws_didit_api_exception_on_error(): void
    {
        Http::fake([
            'https://verification.didit.me/v3/session/sess_unknown/decision/' => Http::response([
                'error' => 'session_not_found',
            ], 404),
        ]);

        $this->expectException(DiditApiException::class);
        $this->provider->getDecision('sess_unknown');
    }

    public function test_verify_webhook_signature_valid(): void
    {
        $payload = json_encode([
            'session_id' => 'sess_123',
            'status' => 'approved',
            'vendor_data' => 'user-uuid',
        ]);
        $timestamp = (string) time();

        $canonicalJson = DiditIdentityProvider::canonicalizeJson($payload);
        $toSign = "{$timestamp}:{$canonicalJson}";
        $validSignature = hash_hmac('sha256', $toSign, $this->secret);

        $this->assertTrue($this->provider->verifyWebhookSignature($payload, $validSignature, $timestamp));
        $this->assertTrue($this->provider->verifyWebhookSignature($payload, 'sha256=' . $validSignature, $timestamp));
    }

    public function test_verify_webhook_signature_invalid_signature_fails(): void
    {
        $payload = json_encode(['session_id' => 'sess_123', 'status' => 'approved']);
        $timestamp = (string) time();

        $this->assertFalse($this->provider->verifyWebhookSignature($payload, 'wrong_signature_hex', $timestamp));
    }

    public function test_verify_webhook_signature_expired_timestamp_fails(): void
    {
        $payload = json_encode(['session_id' => 'sess_123', 'status' => 'approved']);
        $expiredTimestamp = (string) (time() - 301); // 5 min and 1 sec ago

        $canonicalJson = DiditIdentityProvider::canonicalizeJson($payload);
        $toSign = "{$expiredTimestamp}:{$canonicalJson}";
        $signature = hash_hmac('sha256', $toSign, $this->secret);

        $this->assertFalse($this->provider->verifyWebhookSignature($payload, $signature, $expiredTimestamp));
    }

    public function test_canonicalization_keys_order_independence(): void
    {
        $timestamp = (string) time();

        // Object 1: keys in one order
        $json1 = '{"status":"approved","session_id":"sess_123","details":{"b":2,"a":1}}';
        // Object 2: keys in different order
        $json2 = '{"session_id":"sess_123","details":{"a":1,"b":2},"status":"approved"}';

        $canon1 = DiditIdentityProvider::canonicalizeJson($json1);
        $canon2 = DiditIdentityProvider::canonicalizeJson($json2);

        $this->assertEquals($canon1, $canon2);

        $toSign = "{$timestamp}:{$canon1}";
        $signature = hash_hmac('sha256', $toSign, $this->secret);

        // Both raw payloads must validate against the same signature
        $this->assertTrue($this->provider->verifyWebhookSignature($json1, $signature, $timestamp));
        $this->assertTrue($this->provider->verifyWebhookSignature($json2, $signature, $timestamp));
    }
}
