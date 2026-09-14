<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Infrastructure\Persistence\Eloquent\WorkQuoteModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkQuoteUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkSetup(): array
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Unicidad',
            'email' => 'client_uniq_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $client->assignRole('client');

        $proUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Unicidad',
            'email' => 'pro_uniq_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $proUser->assignRole('provider');

        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $proUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $providerProfile->id,
            'overall_verification_status' => 'approved',
        ]);

        $category = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cerrajería',
            'slug' => 'cerrajeria_' . Str::random(4),
        ]);

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Apertura de puerta',
            'status' => 'pending_matching',
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => 'confirmed',
            'agreed_price' => 15000,
        ]);

        return [$client, $proUser, $providerProfile, $work];
    }

    /**
     * Test 1: Un trabajo con presupuesto aceptado rechaza la aceptación de un segundo presupuesto pendiente, y el primero permanece aceptado.
     */
    public function test_work_with_accepted_quote_rejects_accepting_second_pending_quote_and_first_remains_accepted(): void
    {
        [$client, $proUser, $providerProfile, $work] = $this->createWorkSetup();

        $quote1 = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 15000,
            'currency' => 'ARS',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $quote2 = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 20000,
            'currency' => 'ARS',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes/{$quote2->uuid}/accept");

        $response->assertStatus(422);

        $this->assertEquals('accepted', $quote1->fresh()->status);
        $this->assertEquals('pending', $quote2->fresh()->status);
    }

    /**
     * Test 2: Un trabajo con presupuesto aceptado rechaza el envío de presupuesto final.
     */
    public function test_work_with_accepted_quote_rejects_submitting_final_quote(): void
    {
        [$client, $proUser, $providerProfile, $work] = $this->createWorkSetup();

        WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 15000,
            'currency' => 'ARS',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($proUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote", [
                'final_price' => 25000,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 3: Un trabajo con presupuesto aceptado rechaza la confirmación de presupuesto final, y no se crea ningún presupuesto adicional.
     */
    public function test_work_with_accepted_quote_rejects_confirming_final_quote_without_creating_extra_quote(): void
    {
        [$client, $proUser, $providerProfile, $work] = $this->createWorkSetup();

        WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 15000,
            'currency' => 'ARS',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $work->update(['final_price' => 25000]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote/confirm");

        $response->assertStatus(422);

        $this->assertEquals(1, WorkQuoteModel::where('work_id', $work->id)->count());
    }

    /**
     * Test 4: Insertar directamente en base de datos un segundo presupuesto aceptado para el mismo trabajo falla por la restricción, sin pasar por HTTP.
     */
    public function test_direct_db_insertion_of_second_accepted_quote_fails_due_to_unique_constraint(): void
    {
        [$client, $proUser, $providerProfile, $work] = $this->createWorkSetup();

        WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 15000,
            'currency' => 'ARS',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 20000,
            'currency' => 'ARS',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    /**
     * Test 5: Un trabajo sin presupuestos aceptados sigue permitiendo aceptar uno.
     */
    public function test_work_without_accepted_quotes_allows_accepting_one(): void
    {
        [$client, $proUser, $providerProfile, $work] = $this->createWorkSetup();

        $quote = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $providerProfile->id,
            'client_id' => $client->id,
            'amount' => 18000,
            'currency' => 'ARS',
            'status' => 'pending',
            'valid_until' => now()->addDays(5),
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/quotes/{$quote->uuid}/accept");

        $response->assertStatus(200);
        $this->assertEquals('accepted', $quote->fresh()->status);
    }

    /**
     * Test 6: Un trabajo de diagnóstico presencial sin presupuesto previo sigue permitiendo enviar y confirmar el presupuesto final.
     */
    public function test_onsite_diagnosis_work_without_prior_quote_allows_submitting_and_confirming_final_quote(): void
    {
        [$client, $proUser, $providerProfile, $work] = $this->createWorkSetup();

        $work->update([
            'status' => 'pending_diagnosis_quote',
            'agreed_price' => 0,
        ]);

        // Submit final quote
        $subRes = $this->actingAs($proUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote", [
                'final_price' => 30000,
            ]);
        $subRes->assertStatus(200);

        // Confirm final quote
        $confRes = $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/final-quote/confirm");
        $confRes->assertStatus(200);

        $this->assertEquals(1, WorkQuoteModel::where('work_id', $work->id)->where('status', 'accepted')->count());
        $this->assertEquals(30000, $work->fresh()->agreed_price);
    }
}
