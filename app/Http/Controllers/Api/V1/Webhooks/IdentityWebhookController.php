<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Identity\Services\IdentityService;
use App\Http\Controllers\Controller;
use App\Infrastructure\KYC\IdentityProviderContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IdentityWebhookController extends Controller
{
    public function __construct(
        protected IdentityProviderContract $identityProvider,
        protected IdentityService $identityService
    ) {}

    public function handleVerified(Request $request): JsonResponse
    {
        $signature = $request->header('X-Signature-V2');
        $timestamp = $request->header('X-Timestamp');
        $rawPayload = $request->getContent();

        if (empty($signature) || empty($timestamp)) {
            Log::warning('Didit webhook missing required headers', [
                'ip' => $request->ip(),
                'has_signature' => !empty($signature),
                'has_timestamp' => !empty($timestamp),
            ]);

            return response()->json(['error' => 'Missing signature or timestamp header.'], 401);
        }

        // Verify HMAC-SHA256 signature with 5-minute timestamp window & JSON canonicalization
        if (!$this->identityProvider->verifyWebhookSignature($rawPayload, $signature, $timestamp)) {
            Log::warning('Didit webhook signature validation failed', [
                'ip' => $request->ip(),
                'timestamp' => $timestamp,
            ]);

            return response()->json(['error' => 'Invalid signature or expired timestamp.'], 401);
        }

        $payload = $request->json()->all();
        $sessionId = $payload['session_id'] ?? $payload['id'] ?? null;

        if (!$sessionId) {
            // Valid signature acknowledged, but no actionable session
            return response()->json(['status' => 'ignored', 'message' => 'Missing session_id.'], 200);
        }

        // Fetch decision directly from Didit API (webhook is notification, not truth)
        $result = $this->identityService->applyDecision($sessionId);

        // Always return 200 on valid signature so Didit does not duplicate retries
        return response()->json($result, 200);
    }
}
