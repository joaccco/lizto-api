<?php

namespace Tests\Feature;

use App\Application\Notifications\Services\PushNotificationService;
use App\Domain\Notifications\DTOs\PushNotificationMessage;
use App\Infrastructure\Notifications\Contracts\FcmTransportInterface;
use App\Infrastructure\Notifications\Fakes\FcmTransportFake;
use App\Infrastructure\Notifications\Jobs\SendPushNotificationJob;
use App\Infrastructure\Persistence\Eloquent\UserDeviceModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushNotificationInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected FcmTransportFake $fcmFake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fcmFake = new FcmTransportFake();
        $this->app->instance(FcmTransportInterface::class, $this->fcmFake);
    }

    private function createUser(): UserModel
    {
        return UserModel::create([
            'name' => 'Usuario Test ' . Str::random(4),
            'email' => 'user_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
    }

    /** 1. Un usuario autenticado registra un token y queda asociado a su cuenta con la plataforma indicada. */
    public function test_authenticated_user_can_register_device_token(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/devices', [
            'device_token' => 'fcm_token_123',
            'platform' => 'web',
            'device_identifier' => 'browser_chrome_1',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_token' => 'fcm_token_123',
            'platform' => 'web',
            'device_identifier' => 'browser_chrome_1',
            'revoked_at' => null,
        ]);
    }

    /** 2. Registrar el mismo token dos veces no genera duplicados. */
    public function test_registering_same_token_twice_does_not_create_duplicates(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/devices', [
            'device_token' => 'fcm_token_123',
            'platform' => 'web',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/devices', [
            'device_token' => 'fcm_token_123',
            'platform' => 'web',
        ]);

        $this->assertEquals(1, UserDeviceModel::where('device_token', 'fcm_token_123')->count());
    }

    /** 3. Registrar un token que pertenecía a otro usuario lo reasigna al usuario actual. */
    public function test_registering_existing_token_reassigns_it_to_current_user(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();

        $this->actingAs($userA, 'sanctum')->postJson('/api/v1/devices', [
            'device_token' => 'fcm_token_shared',
            'platform' => 'android',
        ]);

        $this->actingAs($userB, 'sanctum')->postJson('/api/v1/devices', [
            'device_token' => 'fcm_token_shared',
            'platform' => 'ios',
        ]);

        $this->assertEquals(1, UserDeviceModel::where('device_token', 'fcm_token_shared')->count());
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $userB->id,
            'device_token' => 'fcm_token_shared',
            'platform' => 'ios',
            'revoked_at' => null,
        ]);
    }

    /** 4. Una plataforma no permitida es rechazada. */
    public function test_invalid_platform_is_rejected(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/devices', [
            'device_token' => 'fcm_token_123',
            'platform' => 'windows_phone',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['platform']);
    }

    /** 5. Un usuario no puede dar de baja un token que no le pertenece. */
    public function test_user_cannot_unregister_token_belonging_to_another_user(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();

        UserDeviceModel::create([
            'user_id' => $userA->id,
            'device_token' => 'token_user_a',
            'platform' => 'web',
            'last_active_at' => now(),
        ]);

        $response = $this->actingAs($userB, 'sanctum')->deleteJson('/api/v1/devices', [
            'device_token' => 'token_user_a',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('user_devices', [
            'device_token' => 'token_user_a',
            'revoked_at' => null,
        ]);
    }

    /** 6. Enviar a un usuario con tres dispositivos activos produce tres envíos. */
    public function test_sending_to_user_with_three_active_devices_produces_three_sends(): void
    {
        $user = $this->createUser();

        UserDeviceModel::create(['user_id' => $user->id, 'device_token' => 'tok_1', 'platform' => 'web', 'last_active_at' => now()]);
        UserDeviceModel::create(['user_id' => $user->id, 'device_token' => 'tok_2', 'platform' => 'ios', 'last_active_at' => now()]);
        UserDeviceModel::create(['user_id' => $user->id, 'device_token' => 'tok_3', 'platform' => 'android', 'last_active_at' => now()]);

        $message = new PushNotificationMessage(
            eventType: 'work_requested',
            title: 'Nuevo trabajo',
            body: 'Tienes una nueva solicitud',
            targetScreen: 'WORK_DETAIL',
            targetParams: ['work_id' => '123']
        );

        $job = new SendPushNotificationJob($user->id, $message->toArray());
        $job->handle($this->fcmFake);

        $this->assertCount(3, $this->fcmFake->getSentMessages());
        $this->assertTrue($this->fcmFake->hasSentToToken('tok_1'));
        $this->assertTrue($this->fcmFake->hasSentToToken('tok_2'));
        $this->assertTrue($this->fcmFake->hasSentToToken('tok_3'));
    }

    /** 7. Los dispositivos revocados no reciben envíos. */
    public function test_revoked_devices_do_not_receive_notifications(): void
    {
        $user = $this->createUser();

        UserDeviceModel::create(['user_id' => $user->id, 'device_token' => 'tok_active', 'platform' => 'web', 'last_active_at' => now()]);
        UserDeviceModel::create(['user_id' => $user->id, 'device_token' => 'tok_revoked', 'platform' => 'ios', 'last_active_at' => now(), 'revoked_at' => now()]);

        $message = new PushNotificationMessage(
            eventType: 'work_requested',
            title: 'Nuevo trabajo',
            body: 'Tienes una nueva solicitud',
            targetScreen: 'WORK_DETAIL',
            targetParams: ['work_id' => '123']
        );

        $job = new SendPushNotificationJob($user->id, $message->toArray());
        $job->handle($this->fcmFake);

        $this->assertCount(1, $this->fcmFake->getSentMessages());
        $this->assertTrue($this->fcmFake->hasSentToToken('tok_active'));
        $this->assertFalse($this->fcmFake->hasSentToToken('tok_revoked'));
    }

    /** 8. Cuando el transporte informa que un token es inválido, ese registro queda revocado. */
    public function test_token_marked_invalid_by_transport_is_automatically_revoked(): void
    {
        $user = $this->createUser();
        UserDeviceModel::create(['user_id' => $user->id, 'device_token' => 'tok_invalid', 'platform' => 'android', 'last_active_at' => now()]);

        $this->fcmFake->markTokenAsInvalid('tok_invalid');

        $message = new PushNotificationMessage(
            eventType: 'work_requested',
            title: 'Nuevo trabajo',
            body: 'Tienes una nueva solicitud',
            targetScreen: 'WORK_DETAIL'
        );

        $job = new SendPushNotificationJob($user->id, $message->toArray());
        $job->handle($this->fcmFake);

        $this->assertDatabaseHas('user_devices', [
            'device_token' => 'tok_invalid',
        ]);
        $device = UserDeviceModel::where('device_token', 'tok_invalid')->first();
        $this->assertNotNull($device->revoked_at);
    }

    /** 9. Cuando el transporte falla por un error temporal, el token no queda revocado. */
    public function test_transient_transport_error_does_not_revoke_token(): void
    {
        $user = $this->createUser();
        UserDeviceModel::create(['user_id' => $user->id, 'device_token' => 'tok_temp_error', 'platform' => 'web', 'last_active_at' => now()]);

        $this->fcmFake->markTokenAsTransientError('tok_temp_error');

        $message = new PushNotificationMessage(
            eventType: 'work_requested',
            title: 'Nuevo trabajo',
            body: 'Tienes una nueva solicitud',
            targetScreen: 'WORK_DETAIL'
        );

        try {
            $job = new SendPushNotificationJob($user->id, $message->toArray());
            $job->handle($this->fcmFake);
        } catch (\Throwable $e) {
            // Transient error throws so queue worker can retry job
        }

        $device = UserDeviceModel::where('device_token', 'tok_temp_error')->first();
        $this->assertNull($device->revoked_at);
    }

    /** 10. Si faltan las credenciales de configuración, el envío no lanza excepción hacia la capa llamante. */
    public function test_missing_configuration_credentials_does_not_throw_exception_to_caller(): void
    {
        $user = $this->createUser();
        UserDeviceModel::create(['user_id' => $user->id, 'device_token' => 'tok_1', 'platform' => 'web', 'last_active_at' => now()]);

        config(['services.fcm.credentials_file' => null, 'services.fcm.credentials_json' => null, 'services.fcm.project_id' => null]);

        $service = app(PushNotificationService::class);

        $message = new PushNotificationMessage(
            eventType: 'test_event',
            title: 'Test',
            body: 'Test body',
            targetScreen: 'HOME'
        );

        // Should complete without throwing exception
        $service->sendToUser($user, $message);

        $this->assertTrue(true);
    }

    /** 11. El envío se despacha a la cola y no se ejecuta de forma sincrónica. */
    public function test_notification_sending_is_dispatched_to_queue(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $service = app(PushNotificationService::class);

        $message = new PushNotificationMessage(
            eventType: 'work_requested',
            title: 'Nuevo trabajo',
            body: 'Mensaje de prueba',
            targetScreen: 'WORK_DETAIL'
        );

        $service->sendToUser($user, $message);

        Queue::assertPushed(SendPushNotificationJob::class, function ($job) use ($user) {
            return $job->userId === $user->id;
        });
    }
}
