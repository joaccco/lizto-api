<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ConversationModel;
use App\Infrastructure\Persistence\Eloquent\MessageModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    private function createConversationSetup(): array
    {
        $category = CategoryModel::first();

        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Duenio',
            'email' => 'client_owner_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $proUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Profesional Participante',
            'email' => 'pro_participant_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $proProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $proUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $proProfile->id,
            'overall_verification_status' => 'approved',
        ]);

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de aire acondicionado',
            'status' => 'pending_matching',
        ]);

        $work = \App\Infrastructure\Persistence\Eloquent\WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $proProfile->id,
            'status' => 'confirmed',
            'agreed_price' => 15000,
        ]);

        $conversation = ConversationModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $proProfile->id,
        ]);

        return [$client, $proUser, $proProfile, $sr, $conversation];
    }

    public function test_unrelated_provider_cannot_read_conversation(): void
    {
        [$client, $proUser, $proProfile, $sr, $conversation] = $this->createConversationSetup();

        $outsiderProUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Profesional Ajeno',
            'email' => 'pro_outsider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $outsiderProUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        $response = $this->actingAs($outsiderProUser, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages");

        $response->assertStatus(403);
    }

    public function test_unrelated_provider_cannot_write_in_conversation(): void
    {
        [$client, $proUser, $proProfile, $sr, $conversation] = $this->createConversationSetup();

        $outsiderProUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Profesional Ajeno',
            'email' => 'pro_outsider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $outsiderProUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        $response = $this->actingAs($outsiderProUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
                'content' => 'Intento de mensaje espía',
            ]);

        $response->assertStatus(403);
    }

    public function test_unrelated_client_cannot_read_or_write_in_conversation(): void
    {
        [$client, $proUser, $proProfile, $sr, $conversation] = $this->createConversationSetup();

        $outsiderClient = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Ajeno',
            'email' => 'client_outsider_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        // Read attempt -> 403
        $readRes = $this->actingAs($outsiderClient, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages");
        $readRes->assertStatus(403);

        // Write attempt -> 403
        $writeRes = $this->actingAs($outsiderClient, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
                'content' => 'Intento de mensaje cliente ajeno',
            ]);
        $writeRes->assertStatus(403);
    }

    public function test_conversation_client_owner_and_assigned_provider_can_read_and_write(): void
    {
        [$client, $proUser, $proProfile, $sr, $conversation] = $this->createConversationSetup();

        // 1. Client owner writes message -> 201
        $clientWriteRes = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
                'content' => 'Hola, ¿cuándo podrías venir?',
            ]);
        $clientWriteRes->assertStatus(201);

        // 2. Assigned provider reads messages -> 200
        $proReadRes = $this->actingAs($proUser, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages");
        $proReadRes->assertStatus(200)
            ->assertJsonPath('data.conversation_id', $conversation->uuid);

        // 3. Assigned provider writes reply -> 201
        $proWriteRes = $this->actingAs($proUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
                'content' => 'Puedo ir mañana a las 10:00 hs.',
            ]);
        $proWriteRes->assertStatus(201);

        // 4. Client owner reads messages -> 200
        $clientReadRes = $this->actingAs($client, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages");
        $clientReadRes->assertStatus(200);
        $this->assertCount(2, $clientReadRes->json('data.messages'));
    }
}
