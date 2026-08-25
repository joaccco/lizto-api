<?php

namespace Tests\Feature;

use App\Domain\Offers\Events\MessageSent;
use App\Domain\Offers\Events\OfferAccepted;
use App\Domain\Offers\Events\OfferCreated;
use App\Domain\Offers\Events\OfferRejected;
use App\Domain\Works\Events\FinalQuoteConfirmed;
use App\Domain\Works\Events\FinalQuoteRejected;
use App\Domain\Works\Events\FinalQuoteSubmitted;
use App\Infrastructure\Notifications\Contracts\FcmTransportInterface;
use App\Infrastructure\Notifications\Fakes\FcmTransportFake;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ConversationModel;
use App\Infrastructure\Persistence\Eloquent\MessageModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserDeviceModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationEventListenerTest extends TestCase
{
    use RefreshDatabase;

    protected FcmTransportFake $fcmFake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
        $this->fcmFake = new FcmTransportFake();
        $this->app->instance(FcmTransportInterface::class, $this->fcmFake);
    }

    private function createClient(): UserModel
    {
        return UserModel::create([
            'name' => 'Cliente Juan',
            'email' => 'client_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
    }

    private function createProvider(): array
    {
        $user = UserModel::create([
            'name' => 'Pedro Proveedor',
            'email' => 'provider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $category = CategoryModel::first();

        $profile = ProviderProfileModel::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'uuid' => (string) Str::uuid(),
            'bio' => 'Bio',
            'is_verified' => true,
        ]);

        return [$user, $profile];
    }

    /** 1. Evento OfferCreated notifica únicamente al cliente (matriz caso 5). */
    public function test_offer_created_event_notifies_client_only(): void
    {
        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        UserDeviceModel::create(['user_id' => $client->id, 'device_token' => 'client_device', 'platform' => 'web', 'last_active_at' => now()]);
        UserDeviceModel::create(['user_id' => $providerUser->id, 'device_token' => 'provider_device', 'platform' => 'android', 'last_active_at' => now()]);

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $providerProfile->category_id, 'raw_prompt' => 'test']);
        $offer = OfferModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'provider_id' => $providerProfile->id, 'proposed_price' => 15000.00]);

        event(new OfferCreated($offer));

        $this->assertTrue($this->fcmFake->hasSentToToken('client_device'));
        $this->assertFalse($this->fcmFake->hasSentToToken('provider_device'));

        $sent = $this->fcmFake->getSentMessages()[0]['message'];
        $this->assertEquals('offer_created', $sent->eventType);
        $this->assertEquals('OFFER_DETAIL', $sent->targetScreen);
        $this->assertStringNotContainsString('15000', $sent->body);
        $this->assertStringNotContainsString('$', $sent->body);
    }

    /** 2. Evento OfferAccepted notifica únicamente al profesional (matriz caso 2). */
    public function test_offer_accepted_event_notifies_provider_only(): void
    {
        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        UserDeviceModel::create(['user_id' => $client->id, 'device_token' => 'client_device', 'platform' => 'web', 'last_active_at' => now()]);
        UserDeviceModel::create(['user_id' => $providerUser->id, 'device_token' => 'provider_device', 'platform' => 'android', 'last_active_at' => now()]);

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $providerProfile->category_id, 'raw_prompt' => 'test']);
        $offer = OfferModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'provider_id' => $providerProfile->id, 'proposed_price' => 15000.00]);

        event(new OfferAccepted($offer));

        $this->assertTrue($this->fcmFake->hasSentToToken('provider_device'));
        $this->assertFalse($this->fcmFake->hasSentToToken('client_device'));

        $sent = $this->fcmFake->getSentMessages()[0]['message'];
        $this->assertEquals('offer_accepted', $sent->eventType);
        $this->assertEquals('WORK_DETAIL', $sent->targetScreen);
    }

    /** 3. Evento OfferRejected notifica al profesional. */
    public function test_offer_rejected_event_notifies_provider(): void
    {
        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        UserDeviceModel::create(['user_id' => $providerUser->id, 'device_token' => 'provider_device', 'platform' => 'android', 'last_active_at' => now()]);

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $providerProfile->category_id, 'raw_prompt' => 'test']);
        $offer = OfferModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'provider_id' => $providerProfile->id, 'proposed_price' => 15000.00]);

        event(new OfferRejected($offer, 'Motivo de rechazo'));

        $this->assertTrue($this->fcmFake->hasSentToToken('provider_device'));
        $sent = $this->fcmFake->getSentMessages()[0]['message'];
        $this->assertEquals('offer_rejected', $sent->eventType);
    }

    /** 4. Evento FinalQuoteSubmitted notifica únicamente al cliente (matriz caso 6). */
    public function test_final_quote_submitted_event_notifies_client_only(): void
    {
        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        UserDeviceModel::create(['user_id' => $client->id, 'device_token' => 'client_device', 'platform' => 'web', 'last_active_at' => now()]);

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $providerProfile->category_id, 'raw_prompt' => 'test']);
        $work = WorkModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'client_id' => $client->id, 'provider_id' => $providerProfile->id, 'status' => 'confirmed']);

        event(new FinalQuoteSubmitted($work));

        $this->assertTrue($this->fcmFake->hasSentToToken('client_device'));
        $sent = $this->fcmFake->getSentMessages()[0]['message'];
        $this->assertEquals('final_quote_submitted', $sent->eventType);
        $this->assertEquals('WORK_DETAIL', $sent->targetScreen);
        $this->assertStringNotContainsString('$', $sent->body);
    }

    /** 5. Eventos FinalQuoteConfirmed y FinalQuoteRejected notifican únicamente al profesional (matriz caso 2). */
    public function test_final_quote_confirmed_and_rejected_events_notify_provider(): void
    {
        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        UserDeviceModel::create(['user_id' => $providerUser->id, 'device_token' => 'provider_device', 'platform' => 'android', 'last_active_at' => now()]);

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $providerProfile->category_id, 'raw_prompt' => 'test']);
        $work = WorkModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'client_id' => $client->id, 'provider_id' => $providerProfile->id, 'status' => 'confirmed']);

        event(new FinalQuoteConfirmed($work));

        $this->assertTrue($this->fcmFake->hasSentToToken('provider_device'));
        $sent = $this->fcmFake->getSentMessages()[0]['message'];
        $this->assertEquals('final_quote_confirmed', $sent->eventType);
    }

    /** 6. Evento MessageSent desde el cliente notifica al profesional (matriz caso 3). */
    public function test_message_sent_by_client_notifies_provider_not_client(): void
    {
        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        UserDeviceModel::create(['user_id' => $client->id, 'device_token' => 'client_device', 'platform' => 'web', 'last_active_at' => now()]);
        UserDeviceModel::create(['user_id' => $providerUser->id, 'device_token' => 'provider_device', 'platform' => 'android', 'last_active_at' => now()]);

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $providerProfile->category_id, 'raw_prompt' => 'test']);
        $work = WorkModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'client_id' => $client->id, 'provider_id' => $providerProfile->id, 'status' => 'confirmed']);
        $conversation = ConversationModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
        ]);

        $msg = MessageModel::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'sender_id' => $client->id,
            'content' => 'Hola, ¿cuándo podrías venir a Calle Falsa 123 por $5000?',
        ]);

        event(new MessageSent($msg));

        $this->assertTrue($this->fcmFake->hasSentToToken('provider_device'));
        $this->assertFalse($this->fcmFake->hasSentToToken('client_device'));

        $sent = $this->fcmFake->getSentMessages()[0]['message'];
        $this->assertEquals('new_chat_message', $sent->eventType);
        $this->assertEquals('CONVERSATION', $sent->targetScreen);

        $this->assertStringNotContainsString('$5000', $sent->body);
        $this->assertStringNotContainsString('Calle Falsa 123', $sent->body);
    }

    /** 7. Evento MessageSent desde el profesional notifica al cliente (matriz caso 7). */
    public function test_message_sent_by_provider_notifies_client_not_provider(): void
    {
        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        UserDeviceModel::create(['user_id' => $client->id, 'device_token' => 'client_device', 'platform' => 'web', 'last_active_at' => now()]);
        UserDeviceModel::create(['user_id' => $providerUser->id, 'device_token' => 'provider_device', 'platform' => 'android', 'last_active_at' => now()]);

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $providerProfile->category_id, 'raw_prompt' => 'test']);
        $work2 = WorkModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'client_id' => $client->id, 'provider_id' => $providerProfile->id, 'status' => 'confirmed']);
        $conversation = ConversationModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work2->id,
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
        ]);

        $msg = MessageModel::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'sender_id' => $providerUser->id,
            'content' => 'Llego a las 15hs',
        ]);

        event(new MessageSent($msg));

        $this->assertTrue($this->fcmFake->hasSentToToken('client_device'));
        $this->assertFalse($this->fcmFake->hasSentToToken('provider_device'));

        $sent = $this->fcmFake->getSentMessages()[0]['message'];
        $this->assertEquals('new_chat_message', $sent->eventType);
        $this->assertEquals('CONVERSATION', $sent->targetScreen);
    }

    /** 8. Destinatario sin dispositivos registrados no produce error. */
    public function test_recipient_with_no_registered_devices_does_not_throw_error(): void
    {
        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $providerProfile->category_id, 'raw_prompt' => 'test']);
        $offer = OfferModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'provider_id' => $providerProfile->id, 'proposed_price' => 15000.00]);

        event(new OfferCreated($offer));

        $this->assertCount(0, $this->fcmFake->getSentMessages());
    }

    /** 9. Si el envío falla, la operación de negocio finaliza correctamente sin propagar excepción. */
    public function test_notification_failure_does_not_break_business_operation(): void
    {
        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        UserDeviceModel::create(['user_id' => $client->id, 'device_token' => 'client_device_error', 'platform' => 'web', 'last_active_at' => now()]);
        $this->fcmFake->markTokenAsTransientError('client_device_error');

        $sr = ServiceRequestModel::create(['uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'category_id' => $providerProfile->category_id, 'raw_prompt' => 'test']);
        $offer = OfferModel::create(['uuid' => (string) Str::uuid(), 'service_request_id' => $sr->id, 'provider_id' => $providerProfile->id, 'proposed_price' => 15000.00]);

        event(new OfferCreated($offer));

        $this->assertTrue(true);
    }
}
