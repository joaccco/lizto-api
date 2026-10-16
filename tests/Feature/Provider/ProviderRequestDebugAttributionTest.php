<?php

namespace Tests\Feature\Provider;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\ServiceRequests\Enums\RequestStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El bloque `_debug` de la bandeja del profesional es un instrumento de prueba
 * manual: dice por que cada pedido aparece en esa bandeja. No es producto.
 *
 * Lo que estas pruebas protegen es lo unico que importa de el: que no exista
 * fuera de desarrollo, y que no mienta.
 */
class ProviderRequestDebugAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $providerUser;
    protected ProviderProfileModel $profile;
    protected UserModel $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Roberto Medina',
            'email' => 'roberto.debug@lizto.test',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        $this->profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->providerUser->id,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
            'is_verified' => true,
        ]);

        $this->client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Prueba',
            'email' => 'cliente.debug@lizto.test',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);
    }

    protected function crearPedidoConTrabajoAsignado(): ServiceRequestModel
    {
        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'category_id' => null,
            'raw_prompt' => 'Se me trabo la cerradura',
            'status' => RequestStatus::PendingMatching->value,
            'urgency' => 'immediate',
        ]);

        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'provider_id' => $this->profile->id,
            'client_id' => $this->client->id,
            'status' => WorkStatus::PendingConfirmation,
        ]);

        return $sr;
    }

    public function test_el_bloque_de_diagnostico_nunca_sale_en_produccion(): void
    {
        $sr = $this->crearPedidoConTrabajoAsignado();

        App::detectEnvironment(fn () => 'production');

        $response = $this->actingAs($this->providerUser, 'sanctum')
            ->getJson('/api/v1/provider/work-requests');

        $response->assertStatus(200);

        $item = collect($response->json('data'))->firstWhere('id', $sr->uuid);
        $this->assertNotNull($item, 'El pedido debe seguir apareciendo en produccion.');
        $this->assertArrayNotHasKey(
            '_debug',
            $item,
            'El instrumento de diagnostico no puede viajar en produccion.'
        );
    }

    public function test_el_bloque_de_diagnostico_nunca_sale_en_un_entorno_desconocido(): void
    {
        $sr = $this->crearPedidoConTrabajoAsignado();

        // Lista blanca: cualquier entorno que no sea local o testing queda afuera.
        App::detectEnvironment(fn () => 'staging');

        $response = $this->actingAs($this->providerUser, 'sanctum')
            ->getJson('/api/v1/provider/work-requests');

        $item = collect($response->json('data'))->firstWhere('id', $sr->uuid);
        $this->assertArrayNotHasKey('_debug', $item);
    }

    public function test_un_trabajo_asignado_se_declara_como_propio(): void
    {
        $sr = $this->crearPedidoConTrabajoAsignado();

        $response = $this->actingAs($this->providerUser, 'sanctum')
            ->getJson('/api/v1/provider/work-requests');

        $item = collect($response->json('data'))->firstWhere('id', $sr->uuid);

        $this->assertArrayHasKey('_debug', $item);
        $this->assertTrue($item['_debug']['es_mio']);
        $this->assertSame('trabajo_asignado', $item['_debug']['motivo']);
        $this->assertSame($this->profile->id, $item['_debug']['provider_id']);
        $this->assertSame('Roberto Medina', $item['_debug']['provider_nombre']);
        $this->assertSame($sr->uuid, $item['_debug']['request_uuid']);
    }
}
