<?php

namespace Tests\Feature\E2E;

use App\Domain\Location\Services\LocationPresenter;
use App\Domain\Providers\Enums\DocumentStatus;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityValidationsTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $client;
    protected UserModel $provider1;
    protected UserModel $provider2;
    protected ProviderProfileModel $provider1Profile;
    protected ProviderProfileModel $provider2Profile;
    protected CategoryModel $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);

        $this->client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Ana Garcia',
            'email' => 'ana_security@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->client->assignRole('client');

        $this->category = CategoryModel::first();

        $this->provider1 = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Uno',
            'email' => 'provider1_sec@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->provider1->assignRole('provider');

        $this->provider1Profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->provider1->id,
            'category_id' => $this->category->id,
            'is_verified' => true,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
        ]);

        $this->provider2 = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Dos',
            'email' => 'provider2_sec@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->provider2->assignRole('provider');

        $this->provider2Profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->provider2->id,
            'category_id' => $this->category->id,
            'is_verified' => true,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
        ]);
    }

    /**
     * D1. GEO-PRIVACY (D-01) - Coordenadas exactas NO expuestas a no autorizados
     */
    public function test_d1_geo_privacy_masks_exact_coordinates(): void
    {
        $exactLat = -34.6037;
        $exactLng = -58.3816;
        $exactAddress = 'Av. Santa Fe 1234, Recoleta, Buenos Aires';

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->category->id,
            'raw_prompt' => 'Reparación de cerradura',
            'status' => 'matching_active',
            'location_lat' => $exactLat,
            'location_lng' => $exactLng,
            'location_address' => $exactAddress,
        ]);

        // Paso 1: Proveedor sin work confirmado NO ve coordenadas exactas
        $providerPresentation = LocationPresenter::present($serviceRequest, $this->provider1);
        $this->assertTrue($providerPresentation['is_approximate']);
        $this->assertEquals(500, $providerPresentation['location_radius_meters']);
        $this->assertNotEquals($exactAddress, $providerPresentation['location_address']);

        // Paso 2: Usuario dueño (cliente) SI ve coordenadas exactas
        $ownerPresentation = LocationPresenter::present($serviceRequest, $this->client);
        $this->assertFalse($ownerPresentation['is_approximate']);
        $this->assertEquals($exactLat, $ownerPresentation['location_lat']);
        $this->assertEquals($exactLng, $ownerPresentation['location_lng']);
        $this->assertEquals($exactAddress, $ownerPresentation['location_address']);

        // Paso 3: Una vez que el trabajo está confirmado, el proveedor asignado SI ve exactas
        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::Confirmed,
            'confirmed_at' => now(),
            'work_lat' => $exactLat,
            'work_lng' => $exactLng,
            'work_address' => $exactAddress,
        ]);

        $assignedProviderPresentation = LocationPresenter::present($serviceRequest, $this->provider1);
        $this->assertFalse($assignedProviderPresentation['is_approximate']);
        $this->assertEquals($exactLat, $assignedProviderPresentation['location_lat']);
        $this->assertEquals($exactLng, $assignedProviderPresentation['location_lng']);

        // Paso 4: Tercer usuario/proveedor no asignado SIGUE viendo aproximado
        $otherProviderPresentation = LocationPresenter::present($serviceRequest, $this->provider2);
        $this->assertTrue($otherProviderPresentation['is_approximate']);
    }

    /**
     * D2. ROLE ESCALATION (D-02) - Imposible escalar a admin o saltar validaciones de rol
     */
    public function test_d2_role_escalation_is_prevented(): void
    {
        $newClient = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Attacker User',
            'email' => 'attacker@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $newClient->assignRole('client');

        Sanctum::actingAs($newClient);

        // Crear perfil para el usuario atacante
        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $newClient->id,
            'status' => ProviderProfileStatus::PendingVerification,
        ]);

        // Intento 1: Llamar become-provider con perfil pero sin documentos KYC verificados -> 403 Forbidden
        $escalateRes = $this->postJson('/api/v1/auth/become-provider');
        $escalateRes->assertStatus(403);
        $this->assertTrue($newClient->fresh()->hasRole('client'));
        $this->assertFalse($newClient->fresh()->hasRole('provider'));
        $this->assertFalse($newClient->fresh()->hasRole('admin'));

        // Intento 2: Cargar documento pero en estado 'pending' (no verificado) -> 403 Forbidden
        ProviderDocumentModel::create([
            'uuid' => (string) Str::uuid(),
            'provider_id' => $profile->id,
            'document_type' => 'identity',
            'document_number' => '99887766',
            'file_path' => 'kyc/test.pdf',
            'status' => DocumentStatus::Pending,
        ]);

        $escalateRes2 = $this->postJson('/api/v1/auth/become-provider');
        $escalateRes2->assertStatus(403);
        $this->assertFalse($newClient->fresh()->hasRole('admin'));

        // Intento 3: Intentar registrarse directamente con rol admin en register -> debe registrarse como client
        $registerRes = $this->postJson('/api/v1/auth/register', [
            'name' => 'Fake Admin',
            'email' => 'fake_admin@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '+5491100000000',
            'role' => 'admin',
        ]);

        if ($registerRes->status() === 201) {
            $createdUser = UserModel::where('email', 'fake_admin@test.com')->first();
            $this->assertNotNull($createdUser);
            $this->assertFalse($createdUser->hasRole('admin'));
            $this->assertTrue($createdUser->hasRole('client'));
        }
    }

    /**
     * D3. FAKE DATA ISOLATION (D-03) - Datos con fake_data_source = 'seeder' no aparecen en queries normales
     */
    public function test_d3_fake_data_isolation_excludes_seeder_records(): void
    {
        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => $this->category->id,
            'raw_prompt' => 'Trabajo para test de aislamiento fake data',
            'status' => 'matching_active',
        ]);

        // 1. Crear trabajo real
        $realWork = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::Confirmed,
            'fake_data_source' => null,
        ]);

        // 2. Crear trabajo de seeder fake
        $fakeWork = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider1Profile->id,
            'status' => WorkStatus::Confirmed,
            'fake_data_source' => 'seeder',
        ]);

        // 3. Query normal con global scope aplicado (sin bypass)
        $normalWorks = WorkModel::where('client_id', $this->client->id)->get();

        $this->assertTrue($normalWorks->contains('id', $realWork->id), 'El trabajo real debe estar presente');
        $this->assertFalse($normalWorks->contains('id', $fakeWork->id), 'El trabajo fake_data_source=seeder debe estar excluido');

        // 4. Query directa omitiendo el global scope si lo permite el sistema de auditoría
        $allWorksWithFake = WorkModel::withoutGlobalScope('exclude_fake_data')
            ->where('client_id', $this->client->id)
            ->get();

        $this->assertTrue($allWorksWithFake->contains('id', $fakeWork->id), 'Con withoutGlobalScope debe permitir auditoría');
    }
}
