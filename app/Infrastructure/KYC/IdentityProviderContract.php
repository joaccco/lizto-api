<?php

namespace App\Infrastructure\KYC;

interface IdentityProviderContract
{
    /**
     * Create a verification session in Didit (POST /session/).
     *
     * @param array{
     *     workflow_id: string,
     *     vendor_data: string,
     *     callback?: string,
     *     metadata?: string,
     *     contact_details?: array,
     *     expected_details?: array,
     *     sandbox_scenario?: string
     * } $params
     * @return array{session_id: string, url: string, status: string, session_token?: string}
     */
    public function createSession(array $params): array;

    /**
     * Fetch verification decision from Didit (GET /session/{session_id}/decision/).
     *
     * @param string $sessionId
     * @return array
     */
    public function getDecision(string $sessionId): array;

    /**
     * Verify Didit V2 webhook signature.
     *
     * @param string $rawPayload
     * @param string $signature Hex-encoded HMAC-SHA256 del cuerpo crudo, header X-Signature
     * @param string $timestamp Unix timestamp en segundos, header X-Timestamp (solo ventana anti-replay)
     * @return bool
     */
    public function verifyWebhookSignature(string $rawPayload, string $signature, string $timestamp): bool;
}
