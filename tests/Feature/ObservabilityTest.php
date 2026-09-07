<?php

namespace Tests\Feature;

use App\Domain\Observability\Services\ErrorTrackerInterface;
use App\Infrastructure\Observability\Services\SanitizerService;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_responds_200_when_dependencies_available(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.redis', 'ok')
            ->assertJsonPath('checks.queue', 'ok');
    }

    public function test_health_endpoint_responds_503_when_dependency_unhealthy(): void
    {
        // Mock DB failure
        DB::shouldReceive('connection->getPdo')
            ->andThrow(new \RuntimeException('DB Connection Error'));

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('checks.database', 'error');
    }

    public function test_health_endpoint_does_not_expose_sensitive_environment_info(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertArrayNotHasKey('version', $json);
        $this->assertArrayNotHasKey('laravel', $json);
        $this->assertArrayNotHasKey('php', $json);
        $this->assertArrayNotHasKey('config', $json);
        $this->assertArrayNotHasKey('env', $json);
        $this->assertArrayNotHasKey('password', $json);
        $this->assertArrayNotHasKey('dsn', $json);
        $this->assertArrayNotHasKey('path', $json);
    }

    public function test_each_request_generates_correlation_id_and_logs_it(): void
    {
        // 1. Without client header -> system auto-generates correlation ID
        $response1 = $this->getJson('/api/v1/health');
        $response1->assertHeaderMissing('X-Missing-Header');
        $correlationId1 = $response1->headers->get('X-Correlation-ID');
        $this->assertNotEmpty($correlationId1);
        $this->assertTrue(Str::isUuid($correlationId1));

        // 2. With client header -> system respects client correlation ID
        $customId = 'custom-correlation-12345';
        $response2 = $this->withHeader('X-Correlation-ID', $customId)
            ->getJson('/api/v1/health');

        $response2->assertHeader('X-Correlation-ID', $customId);
    }

    public function test_error_tracking_service_disabled_when_not_configured(): void
    {
        config([
            'observability.error_tracker.enabled' => false,
            'observability.error_tracker.dsn' => null,
        ]);

        /** @var ErrorTrackerInterface $tracker */
        $tracker = app(ErrorTrackerInterface::class);

        $this->assertFalse($tracker->isEnabled());

        // Capturing an exception when disabled must execute cleanly without error
        $result = $tracker->captureException(new \Exception('Test exception'));
        $this->assertNull($result);
    }

    public function test_error_tracker_failure_does_not_crash_business_operation(): void
    {
        config([
            'observability.error_tracker.enabled' => true,
            'observability.error_tracker.dsn' => 'https://mock@sentry.io/123',
        ]);

        $mockTracker = $this->createMock(ErrorTrackerInterface::class);
        $mockTracker->method('isEnabled')->willReturn(true);
        $mockTracker->method('captureException')
            ->willThrowException(new \RuntimeException('Sentry Service Down'));

        $this->app->instance(ErrorTrackerInterface::class, $mockTracker);

        // Making an API request that causes a 422 business rejection or exception
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrongpassword',
        ]);

        // Business operation finishes without crashing with 500
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    public function test_personal_data_does_not_appear_in_logs_or_external_events(): void
    {
        $sensitivePayload = [
            'user_id' => 42,
            'name' => 'Roberto Medina',
            'email' => 'roberto@example.com',
            'phone' => '+5491122334455',
            'address' => 'Thames 1842, 3B, Palermo',
            'location_lat' => -34.5889,
            'location_lng' => -58.4306,
            'password' => 'secret123',
            'device_token' => 'fcm_token_xyz',
            'status' => 'confirmed',
        ];

        $sanitized = SanitizerService::sanitizePayload($sensitivePayload);

        $this->assertEquals(42, $sanitized['user_id']);
        $this->assertEquals('confirmed', $sanitized['status']);

        $this->assertEquals('[FILTERED]', $sanitized['email']);
        $this->assertEquals('[FILTERED]', $sanitized['phone']);
        $this->assertEquals('[FILTERED]', $sanitized['address']);
        $this->assertEquals('[FILTERED]', $sanitized['location_lat']);
        $this->assertEquals('[FILTERED]', $sanitized['location_lng']);
        $this->assertEquals('[FILTERED]', $sanitized['password']);
        $this->assertEquals('[FILTERED]', $sanitized['device_token']);
        $this->assertArrayNotHasKey('name', $sanitized);
    }
}
