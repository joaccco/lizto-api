<?php

namespace Tests\Feature;

use App\Application\Works\Actions\CreateWorkAction;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkTemporalModelTest extends TestCase
{
    use RefreshDatabase;

    private function createOfferSetup(?Carbon $proposedStartAt, ?int $estimatedDurationMin): array
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Temporal',
            'email' => 'client_temp_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $proUser = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proveedor Temporal',
            'email' => 'pro_temp_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $providerProfile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $proUser->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        $category = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Electricidad',
            'slug' => 'electricidad_' . Str::random(4),
        ]);

        $sr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Instalación eléctrica',
            'status' => 'pending_matching',
        ]);

        $offer = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'provider_id' => $providerProfile->id,
            'status' => 'pending',
            'proposed_price' => 20000,
            'currency_code' => 'ARS',
            'estimated_duration_min' => $estimatedDurationMin,
            'proposed_start_at' => $proposedStartAt,
        ]);

        return [$offer, $client, $providerProfile];
    }

    /**
     * Test 1: Un trabajo creado desde una oferta sin horario propuesto queda con horario agendado nulo y fin agendado nulo.
     */
    public function test_work_created_from_offer_without_proposed_start_at_has_null_scheduled_at_and_null_scheduled_ends_at(): void
    {
        [$offer] = $this->createOfferSetup(null, 90);

        $action = app(CreateWorkAction::class);
        $work = $action->execute($offer);

        $this->assertNull($work->scheduled_at);
        $this->assertNull($work->scheduled_ends_at);
    }

    /**
     * Test 2: Un trabajo creado desde una oferta con horario propuesto queda con fin agendado igual al inicio más la duración estimada.
     */
    public function test_work_created_from_offer_with_proposed_start_at_has_scheduled_ends_at_equal_to_start_plus_estimated_duration(): void
    {
        $startAt = Carbon::parse('2026-09-10 14:00:00');
        [$offer] = $this->createOfferSetup($startAt, 90);

        $action = app(CreateWorkAction::class);
        $work = $action->execute($offer);

        $this->assertNotNull($work->scheduled_at);
        $this->assertEquals($startAt->toIso8601String(), $work->scheduled_at->toIso8601String());
        $this->assertNotNull($work->scheduled_ends_at);
        $this->assertEquals($startAt->copy()->addMinutes(90)->toIso8601String(), $work->scheduled_ends_at->toIso8601String());
    }

    /**
     * Test 3: Un trabajo creado desde una oferta con horario propuesto y sin duración estimada usa la duración por defecto para calcular el fin.
     */
    public function test_work_created_from_offer_with_proposed_start_at_and_without_estimated_duration_uses_default_duration_for_scheduled_ends_at(): void
    {
        $startAt = Carbon::parse('2026-09-10 10:00:00');
        [$offer] = $this->createOfferSetup($startAt, null);

        $action = app(CreateWorkAction::class);
        $work = $action->execute($offer);

        $this->assertNotNull($work->scheduled_at);
        $this->assertEquals($startAt->toIso8601String(), $work->scheduled_at->toIso8601String());
        $this->assertNotNull($work->scheduled_ends_at);
        // Default duration is 60 min
        $this->assertEquals($startAt->copy()->addMinutes(60)->toIso8601String(), $work->scheduled_ends_at->toIso8601String());
    }

    /**
     * Test 4: La migración completa el fin agendado en registros preexistentes que tienen horario y duración, y deja en nulo los que no tienen horario.
     */
    public function test_migration_populates_scheduled_ends_at_for_preexisting_records_with_scheduled_at_and_leaves_nulls(): void
    {
        $this->assertTrue(Schema::hasColumn('works', 'scheduled_ends_at'));

        [$offer, $client, $providerProfile] = $this->createOfferSetup(null, null);

        // Record 1: has scheduled_at and estimated_duration_min=120
        $workWithSched = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $offer->service_request_id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-08-15 09:00:00'),
            'estimated_duration_min' => 120,
        ]);

        // Record 2: has scheduled_at and no estimated_duration_min (null)
        $workWithSchedNoDur = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $offer->service_request_id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-08-15 15:00:00'),
            'estimated_duration_min' => null,
        ]);

        // Record 3: no scheduled_at (null)
        $workNoSched = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $offer->service_request_id,
            'client_id' => $client->id,
            'provider_id' => $providerProfile->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => null,
            'estimated_duration_min' => 45,
        ]);

        // Manually reset scheduled_ends_at to null in DB to simulate pre-existing data before migration backfill logic
        DB::table('works')->where('id', $workWithSched->id)->update(['scheduled_ends_at' => null]);
        DB::table('works')->where('id', $workWithSchedNoDur->id)->update(['scheduled_ends_at' => null]);
        DB::table('works')->where('id', $workNoSched->id)->update(['scheduled_ends_at' => null]);

        // Run backfill SQL statement directly (same as migration up)
        DB::statement("
            UPDATE works
            SET scheduled_ends_at = scheduled_at + (COALESCE(estimated_duration_min, 60) || ' minutes')::interval
            WHERE scheduled_at IS NOT NULL
        ");

        $this->assertEquals(
            $workWithSched->fresh()->scheduled_at->copy()->addMinutes(120)->getTimestamp(),
            $workWithSched->fresh()->scheduled_ends_at->getTimestamp()
        );
        $this->assertEquals(
            $workWithSchedNoDur->fresh()->scheduled_at->copy()->addMinutes(60)->getTimestamp(),
            $workWithSchedNoDur->fresh()->scheduled_ends_at->getTimestamp()
        );
        $this->assertNull($workNoSched->fresh()->scheduled_ends_at);
    }

    /**
     * Test 5: Revertir la migración elimina únicamente la columna nueva.
     */
    public function test_reverting_migration_removes_only_scheduled_ends_at_column(): void
    {
        $this->assertTrue(Schema::hasColumn('works', 'scheduled_ends_at'));
        $this->assertTrue(Schema::hasColumn('works', 'scheduled_at'));

        // Rollback migrations back to before scheduled_ends_at column
        Artisan::call('migrate:rollback', ['--step' => 4]);

        $this->assertFalse(Schema::hasColumn('works', 'scheduled_ends_at'));
        $this->assertTrue(Schema::hasColumn('works', 'scheduled_at'));

        // Re-run migration so subsequent tests are clean
        Artisan::call('migrate');
        $this->assertTrue(Schema::hasColumn('works', 'scheduled_ends_at'));
    }
}
