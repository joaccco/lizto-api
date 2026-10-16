<?php

namespace Tests\Feature\Security;

use App\Domain\Matching\Enums\CardStatus;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\ServiceRequests\Enums\RequestStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Models\ProfessionalMVU;
use Database\Seeders\ClarificationEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Adversarial test suite for SEC-06, SEC-07, SEC-08, SEC-09.
 * Written from the perspective of an attacker to verify authorization invariant enforcement.
 */
class AdversarialAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $attackerClient;
    protected UserModel $legitClient;
    protected UserModel $legitProviderUser;
    protected ProviderProfileModel $legitProviderProfile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClarificationEngineSeeder::class);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'provider', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'moderator', 'guard_name' => 'web']);

        $this->attackerClient = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Attacker Client',
            'email' => 'attacker_' . Str::random(5) . '@lizto.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $this->attackerClient->assignRole('client');

        $this->legitClient = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Legit Client',
            'email' => 'legit_client_' . Str::random(5) . '@lizto.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $this->legitClient->assignRole('client');

        $this->legitProviderUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Legit Provider',
            'email' => 'provider_' . Str::random(5) . '@lizto.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $this->legitProviderUser->assignRole('provider');

        $category = CategoryModel::first();

        $this->legitProviderProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->legitProviderUser->id,
            'category_id' => $category?->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        ProfessionalMVU::create([
            'provider_id' => $this->legitProviderProfile->id,
            'overall_verification_status' => 'approved',
        ]);
    }

    // ==========================================
    // Surface 1 (SEC-06 / T1): Admin Routes Surface
    // ==========================================

    public function test_client_cannot_approve_or_reject_mvu(): void
    {
        $pendingUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pending Provider',
            'email' => 'pending_' . Str::random(5) . '@lizto.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $pendingProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $pendingUser->id,
            'status' => ProviderProfileStatus::PendingVerification,
            'is_verified' => false,
        ]);

        $mvu = ProfessionalMVU::create([
            'provider_id' => $pendingProfile->id,
            'overall_verification_status' => 'pending',
        ]);

        // Attempt approve
        $resApprove = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/admin/mvu/{$mvu->id}/approve");

        $resApprove->assertStatus(403);
        $this->assertEquals('pending', $mvu->fresh()->overall_verification_status);
        $this->assertFalse((bool) $pendingProfile->fresh()->is_verified);

        // Attempt reject
        $resReject = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/admin/mvu/{$mvu->id}/reject", [
                'reason' => 'Motivo malicioso',
            ]);

        $resReject->assertStatus(403);
        $this->assertEquals('pending', $mvu->fresh()->overall_verification_status);
    }

    public function test_client_cannot_read_mvu_pending_or_details(): void
    {
        $pendingUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pending MVU Provider',
            'email' => 'mvu_pend_' . Str::random(5) . '@lizto.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $pendingProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $pendingUser->id,
            'status' => ProviderProfileStatus::PendingVerification,
            'is_verified' => false,
        ]);

        $mvu = ProfessionalMVU::create([
            'provider_id' => $pendingProfile->id,
            'overall_verification_status' => 'pending',
        ]);

        $resPending = $this->actingAs($this->attackerClient, 'sanctum')
            ->getJson('/api/v1/admin/mvu/pending');

        $resPending->assertStatus(403);

        $resShow = $this->actingAs($this->attackerClient, 'sanctum')
            ->getJson("/api/v1/admin/mvu/{$mvu->id}");

        $resShow->assertStatus(403);
    }

    public function test_client_cannot_read_or_manage_providers_via_admin_endpoints(): void
    {
        $resPending = $this->actingAs($this->attackerClient, 'sanctum')
            ->getJson('/api/v1/admin/providers/pending');
        $resPending->assertStatus(403);

        $resShow = $this->actingAs($this->attackerClient, 'sanctum')
            ->getJson("/api/v1/admin/providers/{$this->legitProviderProfile->uuid}");
        $resShow->assertStatus(403);

        $resVerify = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$this->legitProviderProfile->uuid}/verify");
        $resVerify->assertStatus(403);

        $resSuspend = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$this->legitProviderProfile->uuid}/suspend", [
                'reason' => 'Suspensión maliciosa',
            ]);
        $resSuspend->assertStatus(403);

        $resReactivate = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$this->legitProviderProfile->uuid}/reactivate");
        $resReactivate->assertStatus(403);

        $resReject = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/admin/providers/{$this->legitProviderProfile->uuid}/reject", [
                'reason' => 'Rechazo malicioso',
            ]);
        $resReject->assertStatus(403);
    }

    public function test_contract_all_registered_admin_routes_return_403_to_client(): void
    {
        $adminRoutes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'admin/') || str_contains($uri, '/admin/')) {
                $adminRoutes[] = $route;
            }
        }

        $this->assertNotEmpty($adminRoutes, 'No se encontraron rutas de administración registradas.');

        foreach ($adminRoutes as $route) {
            $uri = $route->uri();
            $resolvedUri = preg_replace('/\{[^}]+\}/', '1', $uri);
            $method = $route->methods()[0];

            $response = $this->actingAs($this->attackerClient, 'sanctum')
                ->json($method, $resolvedUri);

            $this->assertEquals(
                403,
                $response->status(),
                "La ruta de administración [{$method} {$uri}] no devolvió 403 ante un usuario con rol 'client'. Código recibido: {$response->status()}."
            );
        }
    }

    // ==========================================
    // Surface 2 (SEC-07 / T2): Offers Authorization
    // ==========================================

    public function test_client_cannot_accept_counter_or_reject_offer_from_another_request(): void
    {
        $category = CategoryModel::first();
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->legitClient->id,
            'category_id' => $category?->id,
            'raw_prompt' => 'Reparación de caño legítima',
            'status' => RequestStatus::Active,
        ]);

        $offer = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $this->legitProviderProfile->id,
            'status' => OfferStatus::Pending,
            'proposed_price' => 20000,
            'currency_code' => 'ARS',
            'round_number' => 1,
        ]);

        // Attacker client attempts accept
        $resAccept = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/offers/{$offer->uuid}/accept");
        $resAccept->assertStatus(403);
        $this->assertEquals(OfferStatus::Pending, $offer->fresh()->status);

        // Attacker client attempts counter
        $resCounter = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/offers/{$offer->uuid}/counter", [
                'proposed_price' => 1000,
            ]);
        $resCounter->assertStatus(403);
        $this->assertEquals(20000, $offer->fresh()->proposed_price);

        // Attacker client attempts reject
        $resReject = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/offers/{$offer->uuid}/reject");
        $resReject->assertStatus(403);
        $this->assertEquals(OfferStatus::Pending, $offer->fresh()->status);
    }

    public function test_provider_cannot_accept_their_own_offer(): void
    {
        $category = CategoryModel::first();
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->legitClient->id,
            'category_id' => $category?->id,
            'raw_prompt' => 'Reparación de caño legítima',
            'status' => RequestStatus::Active,
        ]);

        $offer = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $this->legitProviderProfile->id,
            'status' => OfferStatus::Pending,
            'proposed_price' => 20000,
            'currency_code' => 'ARS',
            'round_number' => 1,
        ]);

        $resAccept = $this->actingAs($this->legitProviderUser, 'sanctum')
            ->postJson("/api/v1/offers/{$offer->uuid}/accept");

        $resAccept->assertStatus(403);
        $this->assertEquals(OfferStatus::Pending, $offer->fresh()->status);
        $this->assertDatabaseMissing('works', [
            'service_request_id' => $serviceRequest->id,
        ]);
    }

    // ==========================================
    // Surface 3 (SEC-08 / T3): Work Modification
    // ==========================================

    public function test_unrelated_user_cannot_modify_work_schedule(): void
    {
        $category = CategoryModel::first();
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->legitClient->id,
            'category_id' => $category?->id,
            'raw_prompt' => 'Trabajo agendado legítimo',
            'status' => RequestStatus::Active,
        ]);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'client_id' => $this->legitClient->id,
            'provider_id' => $this->legitProviderProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => now()->addDays(2),
            'estimated_duration_min' => 60,
        ]);

        $originalScheduledAt = $work->scheduled_at;

        // Attacker client attempts update
        $resUpdate = $this->actingAs($this->attackerClient, 'sanctum')
            ->patchJson("/api/v1/works/{$work->uuid}", [
                'scheduled_at' => now()->addDays(10)->toISOString(),
                'estimated_duration_min' => 120,
            ]);

        $resUpdate->assertStatus(403);
        $this->assertEquals(
            $originalScheduledAt->toDateTimeString(),
            $work->fresh()->scheduled_at->toDateTimeString()
        );
    }

    // ==========================================
    // Surface 4 (SEC-09 / T4): Decline Request
    // ==========================================

    public function test_uninvited_provider_and_client_cannot_decline_request(): void
    {
        $category = CategoryModel::first();
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->legitClient->id,
            'category_id' => $category?->id,
            'raw_prompt' => 'Solicitud pendiente',
            'status' => RequestStatus::MatchingActive,
        ]);

        // Client attempts decline -> 403
        $resClient = $this->actingAs($this->attackerClient, 'sanctum')
            ->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/decline");
        $resClient->assertStatus(403);

        // Uninvited provider attempts decline -> 403
        $resUninvited = $this->actingAs($this->legitProviderUser, 'sanctum')
            ->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/decline");
        $resUninvited->assertStatus(403);

        // ServiceRequest must NOT be cancelled
        $this->assertEquals(RequestStatus::MatchingActive, $serviceRequest->fresh()->status);
    }

    public function test_invited_provider_decline_discards_card_without_cancelling_client_request(): void
    {
        $category = CategoryModel::first();
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->legitClient->id,
            'category_id' => $category?->id,
            'raw_prompt' => 'Solicitud con matching',
            'status' => RequestStatus::MatchingActive,
        ]);

        $matchSession = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
            'total_shown' => 0,
        ]);

        $card = MatchCardModel::create([
            'match_session_id' => $matchSession->id,
            'provider_id' => $this->legitProviderProfile->id,
            'rank_position' => 1,
            'score_total' => 0.95,
            'card_status' => CardStatus::Pending,
        ]);

        // Invited provider declines
        $resDecline = $this->actingAs($this->legitProviderUser, 'sanctum')
            ->postJson("/api/v1/provider/work-requests/{$serviceRequest->uuid}/decline");

        $resDecline->assertStatus(200);
        $resDecline->assertJsonPath('data.status', 'declined');

        // Card is rejected
        $this->assertEquals(CardStatus::Rejected, $card->fresh()->card_status);
        $this->assertNotNull($card->fresh()->decided_at);

        // ServiceRequest status is STILL MatchingActive and NOT Cancelled
        $this->assertEquals(RequestStatus::MatchingActive, $serviceRequest->fresh()->status);
        $this->assertNotEquals(RequestStatus::Cancelled, $serviceRequest->fresh()->status);
    }
}
