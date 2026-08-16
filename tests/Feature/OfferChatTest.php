<?php

namespace Tests\Feature;

use App\Domain\Offers\Events\ContactInfoDetected;
use App\Domain\Offers\Events\OfferAccepted;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ConversationModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\OfferQuestionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceBriefModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfferChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createClient(): UserModel
    {
        return UserModel::create([
            'name' => 'Cliente Test',
            'email' => 'client_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    private function createProvider(): array
    {
        $user = UserModel::create([
            'name' => 'Proveedor Test',
            'email' => 'provider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::where('slug', 'plomeria')->first();

        $profile = ProviderProfileModel::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'uuid' => (string) Str::uuid(),
            'bio' => 'Plomero profesional',
            'is_verified' => true,
            'coverage_radius_km' => 15,
        ]);

        return [$user, $profile];
    }

    public function test_end_to_end_offer_negotiation_and_chat_flow(): void
    {
        Event::fake([ContactInfoDetected::class, OfferAccepted::class]);

        $client = $this->createClient();
        [$providerUser, $providerProfile] = $this->createProvider();

        $category = CategoryModel::where('slug', 'plomeria')->first();

        // 1. Create ServiceRequest with ServiceBrief
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Necesito desobstruir la cañería del lavadero',
            'original_prompt' => 'Necesito desobstruir la cañería del lavadero',
            'status' => 'pending_survey',
        ]);

        ServiceBriefModel::create([
            'service_request_id' => $serviceRequest->id,
            'summary' => 'Solicitud inicial: Necesito desobstruir la cañería del lavadero',
            'attributes' => ['location' => 'lavadero'],
            'is_confirmed' => true,
            'confirmed_at' => now(),
        ]);

        // 2. Provider creates Offer
        $resOffer = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/service-requests/{$serviceRequest->uuid}/offers", [
            'proposed_price' => 15000,
            'currency_code' => 'ARS',
            'estimated_duration_min' => 60,
        ]);

        $resOffer->assertStatus(201);
        $offerUuid = $resOffer->json('data.id');

        // 3. Provider asks ad-hoc question
        $resQuestion = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/offers/{$offerUuid}/questions", [
            'question_key' => 'access_roof',
            'question_text' => '¿Tengo acceso libre al lavadero?',
        ]);

        $resQuestion->assertStatus(201);
        $qId = $resQuestion->json('data.id');

        // 4. Client attempts answering OfferQuestion with Phone Number -> PRE-AGREEMENT BLOCK
        $resBlockedAns = $this->actingAs($client, 'sanctum')->postJson("/api/v1/offer-questions/{$qId}/answer", [
            'answer' => 'Sí, llamame a mi celular al 11 2345 6789',
        ]);

        $resBlockedAns->assertStatus(422)
            ->assertJsonPath('message', 'No se permite compartir datos de contacto (teléfono, WhatsApp, email) antes de la contratación.');

        // 5. Client answers OfferQuestion with valid text
        $resValidAns = $this->actingAs($client, 'sanctum')->postJson("/api/v1/offer-questions/{$qId}/answer", [
            'answer' => 'Sí, el lavadero está abierto en planta baja.',
        ]);

        $resValidAns->assertStatus(200);
        $this->assertDatabaseHas('offer_questions', [
            'id' => $qId,
            'answer' => 'Sí, el lavadero está abierto en planta baja.',
        ]);

        // 6. Provider counter-offers
        $resCounter = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/offers/{$offerUuid}/counter", [
            'proposed_price' => 16500,
        ]);

        $resCounter->assertStatus(200)
            ->assertJsonPath('data.round_number', 2);

        // 7. Client accepts Offer -> Work + Conversation created atomically
        $resAccept = $this->actingAs($client, 'sanctum')->postJson("/api/v1/offers/{$offerUuid}/accept");

        $resAccept->assertStatus(200)
            ->assertJsonPath('data.status', 'accepted');

        $conversationUuid = $resAccept->json('data.conversation_id');
        $workUuid = $resAccept->json('data.work_id');
        $this->assertNotNull($conversationUuid);
        $this->assertNotNull($workUuid);

        // 8. Attempt second acceptance -> Conflict 409
        $resSecondAccept = $this->actingAs($client, 'sanctum')->postJson("/api/v1/offers/{$offerUuid}/accept");
        $resSecondAccept->assertStatus(409);

        // 9. Client sends message in chat
        $resMsg1 = $this->actingAs($client, 'sanctum')->postJson("/api/v1/conversations/{$conversationUuid}/messages", [
            'content' => 'Hola, ya está listo todo para cuando llegues.',
        ]);
        $resMsg1->assertStatus(201);

        // 10. Client sends phone number POST-AGREEMENT -> Message is ALLOWED & audit event dispatched
        $resMsg2 = $this->actingAs($client, 'sanctum')->postJson("/api/v1/conversations/{$conversationUuid}/messages", [
            'content' => 'Por las dudas, mi teléfono es 3794 123456.',
        ]);
        $resMsg2->assertStatus(201);

        Event::assertDispatched(ContactInfoDetected::class);

        // 11. Complete work & verify chat remains accessible
        $work = WorkModel::where('uuid', $workUuid)->first();
        $work->update(['status' => \App\Domain\Works\Enums\WorkStatus::Completed]);

        $resGetMsgs = $this->actingAs($client, 'sanctum')->getJson("/api/v1/conversations/{$conversationUuid}/messages");
        $resGetMsgs->assertStatus(200)
            ->assertJsonCount(2, 'data.messages');
    }
}
