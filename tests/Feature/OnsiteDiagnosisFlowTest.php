<?php

namespace Tests\Feature;

use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\ServiceTypeModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnsiteDiagnosisFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);
    }

    public function test_onsite_diagnosis_flow_requires_visit_path(): void
    {
        $client = UserModel::create([
            'name' => 'Juan Solicitante',
            'email' => 'juan_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'name' => 'Roberto Proveedor',
            'email' => 'roberto_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::where('slug', 'plomeria')->first();
        $serviceType = ServiceTypeModel::where('slug', 'water_leak')->first();
        $serviceType->update(['requires_onsite_diagnosis' => true]);

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $providerUser->id,
            'category_id' => $category->id,
            'uuid' => (string) Str::uuid(),
            'bio' => 'Plomero profesional',
            'is_verified' => true,
            'coverage_radius_km' => 15,
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'service_type_id' => $serviceType->id,
            'raw_prompt' => 'Pérdida de agua en lavadero',
            'status' => 'pending_matching',
        ]);

        // 1. Provider creates Offer -> defaults to requires_visit because water_leak requires onsite diagnosis
        $resOffer = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/service-requests/{$serviceRequest->uuid}/offers", [
            'currency_code' => 'ARS',
        ]);

        $resOffer->assertStatus(201)
            ->assertJsonPath('data.pricing_mode', 'requires_visit');

        $offerUuid = $resOffer->json('data.id');

        // 2. Client accepts offer -> Work enters pending_diagnosis_quote
        $resAccept = $this->actingAs($client, 'sanctum')->postJson("/api/v1/offers/{$offerUuid}/accept");
        $resAccept->assertStatus(200);

        $workUuid = $resAccept->json('data.work_id');
        $work = WorkModel::where('uuid', $workUuid)->first();
        $this->assertEquals(WorkStatus::PendingDiagnosisQuote, $work->status);

        // 3. Client checks progress
        $resProgress1 = $this->actingAs($client, 'sanctum')->getJson("/api/v1/works/{$workUuid}/progress");
        $resProgress1->assertStatus(200)
            ->assertJsonPath('data.status', 'pending_diagnosis_quote')
            ->assertJsonPath('data.next_step_description', 'Roberto Proveedor está evaluando el trabajo presencialmente.');

        // 4. Provider submits final quote after visit
        $resFinalQuote = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/works/{$workUuid}/final-quote", [
            'final_price' => 18500.00,
        ]);
        $resFinalQuote->assertStatus(200);

        // 5. Client checks progress again
        $resProgress2 = $this->actingAs($client, 'sanctum')->getJson("/api/v1/works/{$workUuid}/progress");
        $resProgress2->assertStatus(200)
            ->assertJsonPath('data.next_step_description', 'Esperando que confirmes el precio final cotizado por Roberto Proveedor.');

        // 6. Client confirms final quote -> Work transitions to confirmed
        $resConfirm = $this->actingAs($client, 'sanctum')->postJson("/api/v1/works/{$workUuid}/final-quote/confirm");
        $resConfirm->assertStatus(200);

        $work->refresh();
        $this->assertEquals(WorkStatus::Confirmed, $work->status);
        $this->assertEquals('18500.00', (string) $work->agreed_price);
    }

    public function test_onsite_diagnosis_manual_override_to_quoted(): void
    {
        $client = UserModel::create([
            'name' => 'Juan Solicitante',
            'email' => 'juan_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerUser = UserModel::create([
            'name' => 'Roberto Proveedor',
            'email' => 'roberto_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::where('slug', 'plomeria')->first();
        $serviceType = ServiceTypeModel::where('slug', 'water_leak')->first();
        $serviceType->update(['requires_onsite_diagnosis' => true]);

        $providerProfile = ProviderProfileModel::create([
            'user_id' => $providerUser->id,
            'category_id' => $category->id,
            'uuid' => (string) Str::uuid(),
            'bio' => 'Plomero profesional',
            'is_verified' => true,
            'coverage_radius_km' => 15,
        ]);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'service_type_id' => $serviceType->id,
            'raw_prompt' => 'Pérdida de agua simple',
            'status' => 'pending_matching',
        ]);

        // Provider explicitly sets pricing_mode = quoted with proposed_price
        $resOffer = $this->actingAs($providerUser, 'sanctum')->postJson("/api/v1/service-requests/{$serviceRequest->uuid}/offers", [
            'pricing_mode' => 'quoted',
            'proposed_price' => 12000.00,
        ]);
        $resOffer->assertStatus(201)
            ->assertJsonPath('data.pricing_mode', 'quoted');

        $offerUuid = $resOffer->json('data.id');

        // Client accepts offer -> Work goes directly to confirmed
        $resAccept = $this->actingAs($client, 'sanctum')->postJson("/api/v1/offers/{$offerUuid}/accept");
        $resAccept->assertStatus(200);

        $workUuid = $resAccept->json('data.work_id');
        $work = WorkModel::where('uuid', $workUuid)->first();
        $this->assertEquals(WorkStatus::Confirmed, $work->status);
    }
}
