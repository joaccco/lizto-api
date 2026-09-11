<?php

namespace App\Infrastructure\KYC;

use App\Domain\Identity\Exceptions\DiditApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiditIdentityProvider implements IdentityProviderContract
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $webhookSecret;
    protected int $timeout;

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['base_url'] ?? config('services.didit.base_url', 'https://verification.didit.me/v3'), '/');
        $this->apiKey = $config['api_key'] ?? config('services.didit.api_key', '');
        $this->webhookSecret = $config['webhook_secret'] ?? config('services.didit.webhook_secret', '');
        $this->timeout = (int) ($config['timeout'] ?? config('services.didit.timeout', 15));
    }

    protected function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Create a verification session in Didit (POST /session/).
     */
    public function createSession(array $params): array
    {
        if (empty($params['workflow_id'])) {
            throw new \InvalidArgumentException('workflow_id is required to create a Didit session.');
        }

        if (empty($params['vendor_data'])) {
            throw new \InvalidArgumentException('vendor_data is required to create a Didit session.');
        }

        try {
            // Reintentos solo en fallos transitorios de conexión
            $response = retry(2, function () use ($params) {
                return $this->client()->post('/session/', $params);
            }, 100, function (\Throwable $exception) {
                return $exception instanceof ConnectionException;
            });

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Didit createSession error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => '/session/',
            ]);

            throw new DiditApiException(
                'Failed to create Didit session: ' . $response->body(),
                $response->status(),
                $response->body()
            );
        } catch (DiditApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Didit createSession exception', ['message' => $e->getMessage()]);
            throw new DiditApiException($e->getMessage(), 0, '', $e);
        }
    }

    /**
     * Alias for createSession.
     */
    public function initializeSession(array $params): array
    {
        return $this->createSession($params);
    }

    /**
     * Fetch verification decision from Didit (GET /session/{session_id}/decision/).
     */
    public function getDecision(string $sessionId): array
    {
        if (empty($sessionId)) {
            throw new \InvalidArgumentException('sessionId is required to fetch Didit decision.');
        }

        $endpoint = "/session/{$sessionId}/decision/";

        try {
            $response = retry(2, function () use ($endpoint) {
                return $this->client()->get($endpoint);
            }, 100, function (\Throwable $exception) {
                return $exception instanceof ConnectionException;
            });

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Didit getDecision error', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $endpoint,
            ]);

            throw new DiditApiException(
                "Failed to get Didit decision for session {$sessionId}: " . $response->body(),
                $response->status(),
                $response->body()
            );
        } catch (DiditApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Didit getDecision exception', ['message' => $e->getMessage()]);
            throw new DiditApiException($e->getMessage(), 0, '', $e);
        }
    }

    /**
     * Verify Didit V2 webhook signature.
     */
    public function verifyWebhookSignature(string $rawPayload, string $signature, string $timestamp): bool
    {
        if (empty($this->webhookSecret) || empty($signature) || empty($timestamp)) {
            return false;
        }

        // 1. Rechazar si el timestamp está fuera de una ventana de 5 minutos (300 seg)
        $now = time();
        $ts = (int) $timestamp;
        if (abs($now - $ts) > 300) {
            Log::warning('Didit webhook timestamp outside allowed 5-minute window', [
                'current_time' => $now,
                'provided_timestamp' => $ts,
                'diff_seconds' => abs($now - $ts),
            ]);
            return false;
        }

        // 2. Serializar el cuerpo en JSON canónico: claves ordenadas alfabéticamente, sin espacios.
        $canonicalJson = self::canonicalizeJson($rawPayload);

        // 3. Concatenar timestamp y JSON canónico separados por dos puntos.
        $toSign = "{$timestamp}:{$canonicalJson}";

        // 4. Calcular HMAC-SHA256 de esa cadena con la clave compartida del webhook.
        $computedSignature = hash_hmac('sha256', $toSign, $this->webhookSecret);

        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        // 5. Comparar en tiempo constante.
        return hash_equals($computedSignature, $signature);
    }

    /**
     * Canonicalize JSON recursively (keys sorted alphabetically, compact encoding).
     */
    public static function canonicalizeJson(mixed $data): string
    {
        $sortRecursive = function (&$item) use (&$sortRecursive) {
            if (is_array($item)) {
                if (!array_is_list($item)) {
                    ksort($item, SORT_STRING);
                }
                foreach ($item as &$value) {
                    $sortRecursive($value);
                }
            }
        };

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data = $decoded;
            } else {
                return $data;
            }
        }

        if (is_array($data)) {
            $sortRecursive($data);
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $data;
    }
}
