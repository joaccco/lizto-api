<?php

namespace App\Infrastructure\Notifications;

use App\Domain\Notifications\DTOs\PushNotificationMessage;
use App\Infrastructure\Notifications\Contracts\FcmTransportInterface;
use App\Infrastructure\Notifications\ValueObjects\FcmSendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmHttpTransport implements FcmTransportInterface
{
    public function send(string $deviceToken, PushNotificationMessage $message): FcmSendResult
    {
        $projectId = config('services.fcm.project_id');
        $credentialsFile = config('services.fcm.credentials_file');
        $credentialsJson = config('services.fcm.credentials_json');

        if (empty($projectId) || (empty($credentialsFile) && empty($credentialsJson))) {
            Log::warning('FCM Push Notification dispatch skipped: FCM credentials or project ID missing in configuration.');
            return FcmSendResult::missingCredentials();
        }

        $accessToken = $this->getAccessToken($credentialsFile, $credentialsJson);
        if (!$accessToken) {
            Log::error('FCM Push Notification dispatch failed: Unable to obtain Google OAuth2 access token.');
            return FcmSendResult::missingCredentials('Unable to authenticate with Google FCM Service Account.');
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $payload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $message->title,
                    'body' => $message->body,
                ],
                'data' => $message->toFcmDataPayload(),
                'webpush' => [
                    'notification' => [
                        'title' => $message->title,
                        'body' => $message->body,
                    ],
                ],
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'sound' => 'default',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->successful()) {
                $name = $response->json('name');
                return FcmSendResult::success($name);
            }

            $status = $response->status();
            $responseBody = $response->body();

            if ($status === 404 || str_contains($responseBody, 'UNREGISTERED') || str_contains($responseBody, 'NOT_FOUND') || str_contains($responseBody, 'INVALID_ARGUMENT')) {
                Log::info("FCM Device Token invalid/unregistered: {$deviceToken}. Response: {$responseBody}");
                return FcmSendResult::invalidToken($responseBody);
            }

            Log::warning("FCM HTTP dispatch returned error status {$status} for token {$deviceToken}: {$responseBody}");
            return FcmSendResult::transientError("HTTP {$status}: {$responseBody}");

        } catch (\Throwable $e) {
            Log::error("FCM HTTP connection failed for token {$deviceToken}: " . $e->getMessage());
            return FcmSendResult::transientError($e->getMessage());
        }
    }

    protected function getAccessToken(?string $credentialsFile, ?string $credentialsJson): ?string
    {
        try {
            $credentials = null;
            if (!empty($credentialsJson)) {
                $credentials = json_decode($credentialsJson, true);
            } elseif (!empty($credentialsFile) && file_exists($credentialsFile)) {
                $credentials = json_decode(file_get_contents($credentialsFile), true);
            }

            if (!$credentials || empty($credentials['private_key']) || empty($credentials['client_email'])) {
                return null;
            }

            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $now = time();
            $claims = base64_encode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]));

            $signingInput = "{$header}.{$claims}";
            $privateKey = $credentials['private_key'];
            openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            $jwt = "{$signingInput}." . base64_encode($signature);

            $authResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $authResponse->json('access_token');
        } catch (\Throwable $e) {
            Log::error("Failed generating Google OAuth2 access token for FCM: " . $e->getMessage());
            return null;
        }
    }
}
