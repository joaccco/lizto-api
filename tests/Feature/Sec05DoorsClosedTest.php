<?php

namespace Tests\Feature;

use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ConversationModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Models\ProfessionalMVU;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Sec05DoorsClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createRejectedProviderSetup(): array
    {
        $category = CategoryModel::first();

        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Test',
            'email' => 'client_' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $proUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Profesional Rechazado',
            'email' => 'pro_rejected_' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $proUser->assignRole('provider');

        $proProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $proUser->id,
            'category_id' => $category->id,
            'bio' => 'Profesional sin verificacion valida',
            'status' => ProviderProfileStatus::Rejected,
            'is_verified' => false,
            'coverage_radius_km' => 20,
        ]);

        // Única autoridad de verificación: MVU en estado 'rejected'
        ProfessionalMVU::create([
            'provider_id' => $proProfile->id,
            'overall_verification_status' => 'rejected',
        ]);

        ProviderCategoryModel::create([
            'provider_id' => $proProfile->id,
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        return [$client, $proUser, $proProfile, $category];
    }

    /**
     * Puerta 4: Confirmar una solicitud desde el panel
     */
    public function test_door_4_unverified_provider_cannot_confirm_work_request_from_dashboard(): void
    {
        [$client, $proUser, $proProfile, $category] = $this->createRejectedProviderSetup();

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de cerradura de entrada',
            'status' => 'pending_matching',
        ]);

        $response = $this->actingAs($proUser, 'sanctum')
            ->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/confirm", [
                'estimated_duration_min' => 45,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('works', [
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $proProfile->id,
        ]);
    }

    /**
     * Puerta 5: Emitir un presupuesto intermedio
     */
    public function test_door_5_unverified_provider_cannot_issue_intermediate_quote(): void
    {
        [$client, $proUser, $proProfile, $category] = $this->createRejectedProviderSetup();

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Cambio de cerradura',
            'status' => 'provider_selected',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'client_id' => $client->id,
            'provider_id' => $proProfile->id,
            'status' => WorkStatus::PendingDiagnosisQuote,
        ]);

        $response = $this->actingAs($proUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes", [
                'amount' => 15000,
                'currency' => 'ARS',
                'breakdown_items' => [['concept' => 'Mano de obra', 'price' => 15000]],
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('work_quotes', [
            'work_id' => $work->id,
            'provider_id' => $proProfile->id,
        ]);
    }

    /**
     * Puerta 6: Enviar el presupuesto final
     */
    public function test_door_6_unverified_provider_cannot_submit_final_quote(): void
    {
        [$client, $proUser, $proProfile, $category] = $this->createRejectedProviderSetup();

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Instalación de cerradura digital',
            'status' => 'provider_selected',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'client_id' => $client->id,
            'provider_id' => $proProfile->id,
            'status' => WorkStatus::PendingDiagnosisQuote,
        ]);

        $response = $this->actingAs($proUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote", [
                'final_price' => 25000,
            ]);

        $response->assertStatus(403);
        $this->assertNull($work->fresh()->final_price);
    }

    /**
     * Puerta 7: Escribir en el chat
     */
    public function test_door_7_unverified_provider_cannot_write_in_chat(): void
    {
        [$client, $proUser, $proProfile, $category] = $this->createRejectedProviderSetup();

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de persiana',
            'status' => 'provider_selected',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'client_id' => $client->id,
            'provider_id' => $proProfile->id,
            'status' => WorkStatus::Confirmed,
        ]);

        $conversation = ConversationModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'service_request_id' => $serviceRequest->id,
            'client_id' => $client->id,
            'provider_id' => $proProfile->id,
        ]);

        $response = $this->actingAs($proUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
                'content' => 'Hola, ya salgo para el domicilio.',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $proUser->id,
        ]);
    }

    /**
     * Puerta 8: Aceptar una oferta (lado cliente)
     */
    public function test_door_8_client_cannot_accept_offer_from_unverified_provider(): void
    {
        [$client, $proUser, $proProfile, $category] = $this->createRejectedProviderSetup();

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Arreglo de cortina de enrollar',
            'status' => 'matching_active',
        ]);

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        $matchCard = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $proProfile->id,
            'rank_position' => 1,
            'score_total' => 0.8,
            'card_status' => 'shown',
        ]);

        $offer = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $proProfile->id,
            'status' => OfferStatus::Pending,
            'pricing_mode' => 'quoted',
            'proposed_price' => 12000,
            'round_number' => 1,
        ]);

        // El cliente intenta aceptar la oferta del profesional con identidad rechazada
        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/offers/{$offer->uuid}/accept");

        $response->assertStatus(403);
        $this->assertEquals(OfferStatus::Pending, $offer->fresh()->status);
        $this->assertDatabaseMissing('works', [
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $proProfile->id,
        ]);
    }
}
