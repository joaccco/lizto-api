<?php

namespace Tests\Feature\E2E;

use App\Domain\Providers\Enums\DocumentStatus;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Infrastructure\Persistence\Eloquent\WorkQuoteModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $client;
    protected UserModel $providerUser;
    protected UserModel $adminUser;
    protected ProviderProfileModel $providerProfile;
    protected CategoryModel $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);

        $this->client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente SM',
            'email' => 'cliente_sm@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->client->assignRole('client');

        $this->category = CategoryModel::first();

        $this->providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider SM',
            'email' => 'provider_sm@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->providerUser->assignRole('provider');

        $this->providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->providerUser->id,
            'category_id' => $this->category->id,
            'bio' => 'Especialista',
            'is_verified' => true,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
        ]);

        $this->adminUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Admin SM',
            'email' => 'admin_sm@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->adminUser->assignRole('admin');
    }

    /**
     * E1. Work status transitions - solo transiciones válidas permitidas
     * Flujo válido: Confirmed -> InProgress -> Completed
     * Intentos inválidos: Revertir de Cancelled a Completed -> 409 Conflict
     */
    public function test_e1_work_status_transitions_enforce_valid_lifecycle(): void
    {
        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->category->id,
            'raw_prompt' => 'Arreglo de caño',
            'status' => 'matching_active',
        ]);

        // Paso 1: Crear Work en estado confirmed
        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $quote = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $this->providerProfile->id,
            'client_id' => $this->client->id,
            'amount' => 15000,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        $work->applyAcceptedQuote($quote);

        // Paso 2: Transición válida: Confirmed -> InProgress -> Completed
        Sanctum::actingAs($this->providerUser);

        // Proveedor inicia el trabajo
        $work->transitionTo(WorkStatus::InProgress);
        $this->assertEquals(WorkStatus::InProgress, $work->fresh()->status);

        // Proveedor completa el trabajo vía API
        $completeRes = $this->postJson("/api/v1/works/{$work->uuid}/complete");
        $completeRes->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->assertEquals(WorkStatus::Completed, $work->fresh()->status);

        // Paso 3: Intento de transición INVÁLIDA
        // Un trabajo cancelado no puede completarse
        $cancelledWork = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->providerProfile->id,
            'status' => WorkStatus::Cancelled,
        ]);
        $cancelledQuote = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $cancelledWork->id,
            'provider_id' => $this->providerProfile->id,
            'client_id' => $this->client->id,
            'amount' => 12000,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        $cancelledWork->applyAcceptedQuote($cancelledQuote);

        $invalidRes = $this->postJson("/api/v1/works/{$cancelledWork->uuid}/complete");
        $this->assertTrue(
            in_array($invalidRes->status(), [409, 422], true),
            "Expected 409 or 422 for invalid transition but got {$invalidRes->status()}"
        );
    }

    /**
     * E2. KYC status transitions - solo transiciones permitidas
     * unverified -> pending -> verified
     * Intentos no autorizados o fuera de flujo rechazados
     */
    public function test_e2_kyc_status_transitions_follow_permitted_lifecycle(): void
    {
        $newProviderUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Nuevo Profesional',
            'email' => 'nuevo_pro@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $newProviderUser->assignRole('provider');

        // Paso 1: Estado inicial unverified
        Sanctum::actingAs($newProviderUser);
        $resInitial = $this->getJson('/api/v1/kyc/status');
        $resInitial->assertStatus(200)
            ->assertJsonPath('kyc_status', 'unverified');

        // Paso 2: Transición a pending con documento
        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $newProviderUser->id,
            'category_id' => $this->category->id,
            'status' => ProviderProfileStatus::PendingVerification,
        ]);

        $document = ProviderDocumentModel::create([
            'uuid' => (string) Str::uuid(),
            'provider_id' => $profile->id,
            'document_type' => 'identity',
            'document_number' => '44556677',
            'file_path' => 'kyc/id.pdf',
            'status' => DocumentStatus::Pending,
        ]);

        $resPending = $this->getJson('/api/v1/kyc/status');
        $resPending->assertStatus(200)
            ->assertJsonPath('kyc_status', 'pending');

        // Paso 3: Usuario no admin intenta forzar aprobación directa -> 403 Forbidden
        $unauthorizedApprove = $this->postJson("/api/v1/admin/kyc/documents/{$document->uuid}/verify");
        $unauthorizedApprove->assertStatus(403);
        $this->assertEquals(DocumentStatus::Pending, $document->fresh()->status);

        // Paso 4: Admin aprueba -> transición a verified
        Sanctum::actingAs($this->adminUser);
        $adminApprove = $this->postJson("/api/v1/admin/kyc/documents/{$document->uuid}/verify");
        $adminApprove->assertStatus(200)
            ->assertJsonPath('data.status', 'verified');

        // Paso 5: Consultar como proveedor y constatar verified
        Sanctum::actingAs($newProviderUser);
        $resVerified = $this->getJson('/api/v1/kyc/status');
        $resVerified->assertStatus(200)
            ->assertJsonPath('kyc_status', 'verified');
    }
}
