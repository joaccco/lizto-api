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
    protected ?string $mode;
    protected ?string $defaultScenario;

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['base_url'] ?? config('services.didit.base_url', 'https://verification.didit.me/v3'), '/');
        $this->apiKey = $config['api_key'] ?? config('services.didit.api_key', '');
        $this->webhookSecret = $config['webhook_secret'] ?? config('services.didit.webhook_secret', '');
        $this->timeout = (int) ($config['timeout'] ?? config('services.didit.timeout', 15));
        $this->mode = $config['mode'] ?? config('services.didit.mode');
        $this->defaultScenario = $config['sandbox_scenario'] ?? config('services.didit.sandbox_scenario');
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

        if (empty($params['sandbox_scenario']) && !empty($this->defaultScenario)) {
            $params['sandbox_scenario'] = $this->defaultScenario;
        }

        if (!empty($params['sandbox_scenario'])) {
            $scenario = $params['sandbox_scenario'];

            if (app()->environment('production')) {
                throw new \RuntimeException('Cannot use Didit sandbox scenario in production environment.');
            }

            $mode = $this->mode ?? config('services.didit.mode');
            if ($mode === 'live') {
                throw new \RuntimeException("Contradictory Didit configuration: cannot send sandbox scenario '{$scenario}' when Didit mode is configured as 'live'.");
            }

            if ($mode !== 'sandbox') {
                throw new \RuntimeException("Didit sandbox scenario '{$scenario}' requires Didit mode to be explicitly configured as 'sandbox' (DIDIT_MODE=sandbox). Current mode: " . ($mode ?: 'not configured') . '.');
            }

            if ($scenario !== 'approve' && !str_starts_with($scenario, 'decline_') && !str_starts_with($scenario, 'review_')) {
                throw new \InvalidArgumentException("Invalid Didit sandbox_scenario '{$scenario}'. Allowed values are 'approve', 'decline_*', or 'review_*'.");
            }
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

        // 2. Didit firma el CUERPO CRUDO tal cual llega, sin timestamp ni
        //    canonicalizacion. Verificado contra una entrega real de Didit
        //    (sesion 68d3a20e-d1f6-4580-8142-351e1e1b0a3b, 2026-10-12).
        $computedSignature = hash_hmac('sha256', $rawPayload, $this->webhookSecret);

        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        // 3. Comparar en tiempo constante.
        return hash_equals($computedSignature, $signature);
    }

}
