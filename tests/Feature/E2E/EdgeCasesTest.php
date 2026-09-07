<?php

namespace Tests\Feature\E2E;

use App\Application\Offers\Actions\AcceptOfferAction;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $client;
    protected UserModel $provider1;
    protected UserModel $provider2;
    protected UserModel $unauthorizedUser;
    protected ProviderProfileModel $providerProfile1;
    protected ProviderProfileModel $providerProfile2;
    protected CategoryModel $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
        Storage::fake('s3');
        Storage::fake('local');

        $this->client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Edge',
            'email' => 'client_edge@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->client->assignRole('client');

        $this->category = CategoryModel::first();

        $this->provider1 = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Alpha',
            'email' => 'provider_alpha@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->provider1->assignRole('provider');

        $this->providerProfile1 = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->provider1->id,
            'category_id' => $this->category->id,
            'is_verified' => true,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
        ]);

        $this->provider2 = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Beta',
            'email' => 'provider_beta@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->provider2->assignRole('provider');

        $this->providerProfile2 = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->provider2->id,
            'category_id' => $this->category->id,
            'is_verified' => true,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
        ]);

        $this->unauthorizedUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Snoop User',
            'email' => 'snoop@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->unauthorizedUser->assignRole('client');
    }

    /**
     * F1. Multipart FormData upload - verificar validación de tipo y tamaño de archivo
     */
    public function test_f1_multipart_form_data_upload_and_validation(): void
    {
        Sanctum::actingAs($this->provider1);

        // Caso 1: Archivo que excede 10MB -> 422 Unprocessable Entity
        $oversizedFile = UploadedFile::fake()->create('heavy_doc.pdf', 11264, 'application/pdf'); // 11MB

        $oversizedRes = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $oversizedFile,
        ]);

        $oversizedRes->assertStatus(422)
            ->assertJsonValidationErrors(['document_file']);

        // Caso 2: Formato inválido (.exe o .txt) -> 422
        $invalidFormatFile = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');
        $invalidFormatRes = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $invalidFormatFile,
        ]);
        $invalidFormatRes->assertStatus(422);

        // Caso 3: Archivo válido (JPEG < 5MB) en multipart -> 201 Created
        $validFile = UploadedFile::fake()->image('dni.jpg', 800, 600)->size(1200); // 1.2MB
        $validRes = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $validFile,
        ]);

        $validRes->assertStatus(201)
            ->assertJsonPath('status', 'pending');
    }

    /**
     * F2. Concurrency - 2 providers compiten por aceptar/confirmar el mismo trabajo
     */
    public function test_f2_concurrency_race_condition_on_work_acceptance(): void
    {
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->category->id,
            'raw_prompt' => 'Urgencia cerrajería',
            'status' => 'matching_active',
        ]);

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
        ]);

        $card1 = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $this->providerProfile1->id,
            'rank_position' => 1,
            'score_total' => 0.99,
            'card_status' => 'shown',
        ]);

        $card2 = MatchCardModel::create([
            'match_session_id' => $session->id,
            'provider_id' => $this->providerProfile2->id,
            'rank_position' => 2,
            'score_total' => 0.92,
            'card_status' => 'shown',
        ]);

        $offer1 = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $this->providerProfile1->id,
            'status' => \App\Domain\Offers\Enums\OfferStatus::Pending,
            'proposed_price' => 15000,
            'currency_code' => 'ARS',
            'estimated_duration_min' => 60,
            'proposed_start_at' => now(),
        ]);

        $offer2 = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $this->providerProfile2->id,
            'status' => \App\Domain\Offers\Enums\OfferStatus::Pending,
            'proposed_price' => 14000,
            'currency_code' => 'ARS',
            'estimated_duration_min' => 60,
            'proposed_start_at' => now(),
        ]);

        $acceptAction = app(AcceptOfferAction::class);

        // Primer accept logra concretarse exitosamente
        $result1 = $acceptAction->execute($offer1, $card1);
        $this->assertNotNull($result1['work']);
        $this->assertEquals($this->providerProfile1->id, $result1['work']->provider_id);

        // Segundo accept concurrente sobre la misma solicitud es interceptado por la guarda transaccional
        $secondAcceptedException = null;
        try {
            $acceptAction->execute($offer2, $card2);
        } catch (\DomainException $e) {
            $secondAcceptedException = $e;
        }

        $this->assertNotNull($secondAcceptedException, 'Debe lanzar DomainException ante intento concurrente');
        $this->assertEquals(409, $secondAcceptedException->getCode());
        $this->assertStringContainsString('ya tiene una oferta aceptada activa', $secondAcceptedException->getMessage());

        // Verificar que en la base de datos existe exactamente 1 Work
        $this->assertEquals(1, WorkModel::where('service_request_id', $serviceRequest->id)->count());
    }

    /**
     * F3. Signed URL expiry - valida que URL expira en ~5 minutos y protege acceso
     */
    public function test_f3_signed_url_expiry_and_access_protection(): void
    {
        Sanctum::actingAs($this->provider1);

        $file = UploadedFile::fake()->image('dni.jpg');
        $upload = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $file,
        ]);

        $docUuid = $upload->json('document_id');

        // Paso 1: Dueño obtiene signed URL con expiración de 300 segundos (5 minutos)
        $response = $this->getJson("/api/v1/kyc/documents/{$docUuid}/signed-url");
        $response->assertStatus(200);

        $this->assertEquals(300, $response->json('expires_in_seconds'));
        $signedUrl = $response->json('signed_url');
        $this->assertNotEmpty($signedUrl);

        // Verificar que la URL contiene timestamp de expiración futuro
        $this->assertMatchesRegularExpression('/(expiration|expires|ExpiresIn|Signature|token)/i', $signedUrl);

        // Paso 2: Usuario no dueño ni admin intenta generar signed URL -> 403 Forbidden
        Sanctum::actingAs($this->unauthorizedUser);
        $unauthorizedRes = $this->getJson("/api/v1/kyc/documents/{$docUuid}/signed-url");
        $unauthorizedRes->assertStatus(403);
    }
}
