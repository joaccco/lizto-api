<?php

namespace Tests\Feature\E2E;

use App\Domain\Providers\Enums\DocumentStatus;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderKycWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $providerUser;
    protected UserModel $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('s3');
        Storage::fake('local');

        $this->providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Carlos Electricista',
            'email' => 'carlos_kyc@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->providerUser->assignRole('provider');

        $this->adminUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Admin Lizto',
            'email' => 'admin_kyc@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->adminUser->assignRole('admin');
    }

    /**
     * B1. Provider NO autenticado intenta GET /kyc/status -> 401 Unauthorized
     */
    public function test_b1_unauthenticated_provider_cannot_get_kyc_status(): void
    {
        $response = $this->getJson('/api/v1/kyc/status');
        $response->assertStatus(401);
    }

    /**
     * B2. Provider nuevo obtiene estado KYC inicial -> 200 OK
     */
    public function test_b2_new_provider_gets_initial_unverified_status(): void
    {
        Sanctum::actingAs($this->providerUser);

        $response = $this->getJson('/api/v1/kyc/status');

        $response->assertStatus(200)
            ->assertJsonPath('kyc_status', 'unverified')
            ->assertJsonPath('documents', []);
    }

    /**
     * B3. Provider carga documento válido (jpg, <5MB) -> 201 Created
     */
    public function test_b3_provider_uploads_valid_document(): void
    {
        Sanctum::actingAs($this->providerUser);

        $file = UploadedFile::fake()->image('dni_front.jpg', 800, 600)->size(1500); // 1.5MB

        $response = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $file,
            'expiry_date' => '2030-12-31',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'document_id',
                'document_type',
                'status',
                'created_at',
            ])
            ->assertJsonPath('status', 'pending');

        $docId = $response->json('document_id');
        $this->assertNotEmpty($docId);

        // Verificar en base de datos
        $this->assertDatabaseHas('provider_documents', [
            'uuid' => $docId,
            'document_number' => '12345678',
            'document_type' => 'identity',
            'status' => 'pending',
        ]);

        // Verificar que el estado en /kyc/status es ahora 'pending'
        $statusRes = $this->getJson('/api/v1/kyc/status');
        $statusRes->assertStatus(200)
            ->assertJsonPath('kyc_status', 'pending');
    }

    /**
     * B4. Provider obtiene signed_url para acceder documento (5min expiry) -> 200 OK
     */
    public function test_b4_provider_retrieves_signed_url_with_expiry(): void
    {
        Sanctum::actingAs($this->providerUser);

        $file = UploadedFile::fake()->image('dni.jpg', 800, 600);
        $upload = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $file,
        ]);

        $docId = $upload->json('document_id');

        $response = $this->getJson("/api/v1/kyc/documents/{$docId}/signed-url");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'signed_url',
                'expires_in_seconds',
            ]);

        $this->assertEquals(300, $response->json('expires_in_seconds'));
        $this->assertNotEmpty($response->json('signed_url'));
    }

    /**
     * B5. Provider intenta cargar documento duplicado (mismo document_number) -> 409 Conflict
     */
    public function test_b5_provider_cannot_upload_duplicate_document(): void
    {
        Sanctum::actingAs($this->providerUser);

        $file1 = UploadedFile::fake()->image('doc1.jpg', 800, 600);
        $file2 = UploadedFile::fake()->image('doc2.jpg', 800, 600);

        // Primer upload exitoso
        $first = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $file1,
        ]);
        $first->assertStatus(201);

        // Segundo upload con mismo número y tipo -> 409 Conflict
        $second = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $file2,
        ]);

        $second->assertStatus(409);
        $this->assertStringContainsString('existe', strtolower($second->json('message')));
    }

    /**
     * B6. Provider carga múltiples documentos diferentes -> 201 cada uno, status refleja 3
     */
    public function test_b6_provider_uploads_multiple_documents(): void
    {
        Sanctum::actingAs($this->providerUser);

        $file1 = UploadedFile::fake()->image('dni.jpg');
        $file2 = UploadedFile::fake()->image('passport.jpg');
        $file3 = UploadedFile::fake()->create('license.pdf', 500, 'application/pdf');

        $res1 = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '11111111',
            'document_file' => $file1,
        ]);
        $res1->assertStatus(201);

        $res2 = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'passport',
            'document_number' => '87654321',
            'document_file' => $file2,
        ]);
        $res2->assertStatus(201);

        $res3 = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'driver_license',
            'document_number' => '99999999',
            'document_file' => $file3,
        ]);
        $res3->assertStatus(201);

        $status = $this->getJson('/api/v1/kyc/status');
        $status->assertStatus(200);

        $docs = $status->json('documents');
        $this->assertCount(3, $docs, 'Deben existir 3 documentos cargados');
    }

    /**
     * B7. Admin marca kyc_status = "verified" en backend -> Provider ve verified
     */
    public function test_b7_admin_verifies_document_and_provider_becomes_verified(): void
    {
        Sanctum::actingAs($this->providerUser);
        $file = UploadedFile::fake()->image('dni.jpg');

        $upload = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $file,
        ]);
        $docId = $upload->json('document_id');

        // Admin verifica el documento
        Sanctum::actingAs($this->adminUser);
        $verifyRes = $this->postJson("/api/v1/admin/kyc/documents/{$docId}/verify");
        $verifyRes->assertStatus(200)
            ->assertJsonPath('data.status', 'verified');

        // Provider consulta su estado
        Sanctum::actingAs($this->providerUser);
        $statusRes = $this->getJson('/api/v1/kyc/status');
        $statusRes->assertStatus(200)
            ->assertJsonPath('kyc_status', 'verified');
    }

    /**
     * B8. Provider con kyc verified intenta cargar otro documento -> 201 exitoso
     */
    public function test_b8_verified_provider_can_upload_additional_document(): void
    {
        Sanctum::actingAs($this->providerUser);

        // 1. Carga inicial
        $file1 = UploadedFile::fake()->image('dni.jpg');
        $upload1 = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_number' => '12345678',
            'document_file' => $file1,
        ]);
        $docId = $upload1->json('document_id');

        // 2. Admin verifica
        Sanctum::actingAs($this->adminUser);
        $this->postJson("/api/v1/admin/kyc/documents/{$docId}/verify")->assertStatus(200);

        // 3. Provider verificado carga un segundo documento
        Sanctum::actingAs($this->providerUser);
        $file2 = UploadedFile::fake()->create('matricula.pdf', 300, 'application/pdf');
        $upload2 = $this->postJson('/api/v1/kyc/documents', [
            'document_type' => 'professional_license',
            'document_number' => 'MAT9988',
            'document_file' => $file2,
        ]);

        $upload2->assertStatus(201)
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('provider_documents', [
            'document_number' => 'MAT9988',
            'document_type' => 'professional_license',
        ]);
    }
}
