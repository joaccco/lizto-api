<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\ServiceRequests\Enums\RequestStatus;
use App\Domain\ServiceRequests\Enums\RequestUrgency;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkCompletionBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function createUser(): UserModel
    {
        return UserModel::create([
            'uuid'     => (string) Str::uuid(),
            'name'     => 'User ' . Str::random(5),
            'email'    => 'test_' . Str::random(8) . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    private function createProvider(UserModel $user): ProviderProfileModel
    {
        return ProviderProfileModel::create([
            'uuid'        => (string) Str::uuid(),
            'user_id'     => $user->id,
            'status'      => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);
    }

    private function createWork(UserModel $client, ProviderProfileModel $provider, array $attrs = []): WorkModel
    {
        $sr = ServiceRequestModel::create([
            'uuid'        => (string) Str::uuid(),
            'client_id'   => $client->id,
            'category_id' => CategoryModel::first()?->id ?? 1,
            'raw_prompt'  => 'Reparación de filtración',
            'urgency'     => RequestUrgency::Today,
            'status'      => RequestStatus::ProviderSelected,
        ]);

        return WorkModel::create(array_merge([
            'uuid'               => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id'          => $client->id,
            'provider_id'        => $provider->id,
            'status'             => WorkStatus::Confirmed,
        ], $attrs));
    }

    public function test_completar_con_agreed_price_y_sin_quote_debe_fallar(): void
    {
        $client       = $this->createUser();
        $providerUser = $this->createUser();
        $provider     = $this->createProvider($providerUser);

        $work = $this->createWork($client, $provider);
        $work->agreed_price = 100000;
        $work->save();

        $this->assertSame(
            0,
            $work->quotes()->where('status', 'accepted')->count(),
            'Precondición: el trabajo no debe tener ningún presupuesto aceptado.'
        );

        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(422);

        $work->refresh();
        $this->assertNotEquals(
            WorkStatus::Completed,
            $work->status,
            'Un trabajo sin acuerdo comercial aceptado por el cliente no puede quedar completado.'
        );
    }

    public function test_trabajo_creado_desde_offer_no_puede_completarse_sin_quote(): void
    {
        $client       = $this->createUser();
        $providerUser = $this->createUser();
        $provider     = $this->createProvider($providerUser);

        $work = $this->createWork($client, $provider);
        $work->agreed_price = 85000;
        $work->save();

        $this->assertGreaterThan(
            0,
            (float) $work->agreed_price,
            'Precondición: todo trabajo nacido de un Offer con precio llega con agreed_price > 0.'
        );

        $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete")
            ->assertStatus(422);
    }

    public function test_completar_con_quote_aceptado_funciona(): void
    {
        $client       = $this->createUser();
        $providerUser = $this->createUser();
        $provider     = $this->createProvider($providerUser);

        $work = $this->createWork($client, $provider);

        $quoteUuid = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes", [
                'amount'          => 22000,
                'breakdown_items' => [['concept' => 'Servicio completo', 'price' => 22000]],
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes/{$quoteUuid}/accept")
            ->assertStatus(200);

        $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete")
            ->assertStatus(200);

        $work->refresh();
        $this->assertEquals(WorkStatus::Completed, $work->status);
    }
}
