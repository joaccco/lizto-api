<?php

namespace Tests\Feature;

use App\Application\Matching\Actions\RunMatchingAction;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MatchingScheduleOverlapTest extends TestCase
{
    use RefreshDatabase;

    private function createMatchingSetup(): array
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Matching',
            'email' => 'client_match_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $proUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Matching',
            'email' => 'pro_match_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $category = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Gas',
            'slug' => 'gas_' . Str::random(4),
        ]);

        $providerProfile = ProviderProfileModel::factory()->enabled()->create([
            'user_id' => $proUser->id,
            'base_lat' => -34.6037,
            'base_lng' => -58.3816,
        ]);

        $providerProfile->categories()->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        return [$client, $proUser, $providerProfile, $category];
    }

    private function createRequest(UserModel $client, CategoryModel $category, string $dateStr, string $windowStart, string $windowEnd): ServiceRequestModel
    {
        return ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de estufa',
            'urgency' => 'scheduled',
            'scheduled_date' => $dateStr,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'status' => 'pending_matching',
            'is_remote' => true,
        ]);
    }

    /**
     * Test 1: Un profesional con reserva confirmada que se solapa con la franja pedida queda excluido.
     */
    public function test_provider_with_overlapping_confirmed_reservation_is_excluded(): void
    {
        [$client, $proUser, $providerProfile, $category] = $this->createMatchingSetup();

        $srTarget = $this->createRequest($client, $category, '2026-09-10', '14:00', '18:00');

        // Existing work 15:00 to 16:30 AR time
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srTarget->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-09-10 15:00:00', 'America/Argentina/Buenos_Aires'),
            'estimated_duration_min' => 90,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($srTarget);

        $this->assertEmpty($results);
    }

    /**
     * Test 2: Una reserva fuera de la franja pedida no excluye al profesional.
     */
    public function test_reservation_outside_requested_window_does_not_exclude_provider(): void
    {
        [$client, $proUser, $providerProfile, $category] = $this->createMatchingSetup();

        $srTarget = $this->createRequest($client, $category, '2026-09-10', '14:00', '18:00');

        // Existing work 09:00 to 11:00 AR time (outside 14:00-18:00)
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srTarget->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-09-10 09:00:00', 'America/Argentina/Buenos_Aires'),
            'estimated_duration_min' => 120,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($srTarget);

        $this->assertCount(1, $results);
    }

    /**
     * Test 3: Un trabajo que comienza antes de la franja pedida pero se extiende dentro de ella se detecta como conflicto.
     */
    public function test_work_starting_before_requested_window_and_extending_into_it_is_detected_as_conflict(): void
    {
        [$client, $proUser, $providerProfile, $category] = $this->createMatchingSetup();

        $srTarget = $this->createRequest($client, $category, '2026-09-10', '14:00', '18:00');

        // Existing work 12:00 to 15:00 AR time (starts before 14:00, ends after 14:00)
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srTarget->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-09-10 12:00:00', 'America/Argentina/Buenos_Aires'),
            'estimated_duration_min' => 180,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($srTarget);

        $this->assertEmpty($results);
    }

    /**
     * Test 4: Una reserva que termina exactamente cuando empieza la franja pedida no es conflicto.
     */
    public function test_reservation_ending_exactly_when_requested_window_starts_is_not_a_conflict(): void
    {
        [$client, $proUser, $providerProfile, $category] = $this->createMatchingSetup();

        $srTarget = $this->createRequest($client, $category, '2026-09-10', '14:00', '18:00');

        // Existing work 12:00 to 14:00 AR time (ends exactly at 14:00)
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srTarget->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-09-10 12:00:00', 'America/Argentina/Buenos_Aires'),
            'estimated_duration_min' => 120,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($srTarget);

        $this->assertCount(1, $results);
    }

    /**
     * Test 5: Una reserva que empieza exactamente cuando termina la franja pedida no es conflicto.
     */
    public function test_reservation_starting_exactly_when_requested_window_ends_is_not_a_conflict(): void
    {
        [$client, $proUser, $providerProfile, $category] = $this->createMatchingSetup();

        $srTarget = $this->createRequest($client, $category, '2026-09-10', '14:00', '18:00');

        // Existing work 18:00 to 20:00 AR time (starts exactly at 18:00)
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srTarget->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-09-10 18:00:00', 'America/Argentina/Buenos_Aires'),
            'estimated_duration_min' => 120,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($srTarget);

        $this->assertCount(1, $results);
    }

    /**
     * Test 6: Las comparaciones dan el resultado correcto con horarios expresados en hora local argentina y la aplicación configurada en UTC.
     */
    public function test_comparisons_give_correct_result_with_argentina_local_time_when_app_is_utc(): void
    {
        config(['app.timezone' => 'UTC']);

        [$client, $proUser, $providerProfile, $category] = $this->createMatchingSetup();

        // Requested window in Argentina time: 14:00 to 18:00 (which is 17:00 to 21:00 UTC)
        $srTarget = $this->createRequest($client, $category, '2026-09-10', '14:00', '18:00');

        // Work scheduled at 15:00 AR time
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srTarget->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-09-10 15:00:00', 'America/Argentina/Buenos_Aires'),
            'estimated_duration_min' => 60,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($srTarget);

        // Correct logic must exclude provider (overlap between 14-18 AR and 15-16 AR)
        $this->assertEmpty($results);
    }

    /**
     * Test 7: Un trabajo sin horario agendado no excluye al profesional.
     */
    public function test_work_without_scheduled_at_does_not_exclude_provider(): void
    {
        [$client, $proUser, $providerProfile, $category] = $this->createMatchingSetup();

        $srTarget = $this->createRequest($client, $category, '2026-09-10', '14:00', '18:00');

        // Work with null scheduled_at
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srTarget->id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => null,
            'estimated_duration_min' => 60,
        ]);

        $action = app(RunMatchingAction::class);
        $results = $action->execute($srTarget);

        $this->assertCount(1, $results);
    }
}
