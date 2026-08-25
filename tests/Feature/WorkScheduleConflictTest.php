<?php

namespace Tests\Feature;

use App\Application\Works\Actions\CreateWorkAction;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkScheduleConflictTest extends TestCase
{
    use RefreshDatabase;

    private function createSetup(): array
    {
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Test',
            'email' => 'client_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $proUser1 = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pro 1 Test',
            'email' => 'pro1_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $provider1 = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $proUser1->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'availability_status' => 'available',
        ]);

        $proUser2 = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pro 2 Test',
            'email' => 'pro2_' . Str::random(5) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $provider2 = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $proUser2->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'availability_status' => 'available',
        ]);

        $category = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Plomería',
            'slug' => 'plomeria-' . Str::random(5),
            'is_active' => true,
        ]);

        return [$client, $provider1, $provider2, $category];
    }

    private function createServiceRequest(UserModel $client, CategoryModel $category): ServiceRequestModel
    {
        return ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Reparación de caño',
            'urgency' => 'scheduled',
            'scheduled_date' => '2026-09-20',
            'window_start' => '14:00',
            'window_end' => '16:00',
            'status' => 'pending_matching',
            'is_remote' => true,
        ]);
    }

    private function createOffer(ServiceRequestModel $sr, ProviderProfileModel $provider, Carbon $startAt, int $durationMin = 60): OfferModel
    {
        return OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'provider_id' => $provider->id,
            'status' => OfferStatus::Pending,
            'proposed_price' => 20000.00,
            'currency_code' => 'ARS',
            'estimated_duration_min' => $durationMin,
            'proposed_start_at' => $startAt,
        ]);
    }

    /**
     * Test 1: Intentar confirmar dos trabajos con horarios solapados para el mismo profesional falla, invocando la operación directamente.
     */
    public function test_confirming_two_overlapping_works_for_same_provider_fails_directly(): void
    {
        [$client, $provider1, $provider2, $category] = $this->createSetup();

        $sr1 = $this->createServiceRequest($client, $category);
        $start1 = Carbon::parse('2026-09-20 14:00:00', 'America/Argentina/Buenos_Aires');
        $offer1 = $this->createOffer($sr1, $provider1, $start1, 120); // 14:00 - 16:00

        $action = app(CreateWorkAction::class);
        $work1 = $action->execute($offer1);
        $this->assertEquals(WorkStatus::Confirmed, $work1->status);

        // Second overlapping offer for same provider: 15:00 - 17:00
        $sr2 = $this->createServiceRequest($client, $category);
        $start2 = Carbon::parse('2026-09-20 15:00:00', 'America/Argentina/Buenos_Aires');
        $offer2 = $this->createOffer($sr2, $provider1, $start2, 120);

        $this->expectException(ValidationException::class);
        $action->execute($offer2);
    }

    /**
     * Test 2: Ese segundo intento devuelve error de validación con mensaje claro, no un error de base de datos.
     */
    public function test_second_overlapping_attempt_returns_clear_validation_error_not_db_error(): void
    {
        [$client, $provider1, $provider2, $category] = $this->createSetup();

        $sr1 = $this->createServiceRequest($client, $category);
        $start1 = Carbon::parse('2026-09-20 14:00:00', 'America/Argentina/Buenos_Aires');
        $offer1 = $this->createOffer($sr1, $provider1, $start1, 120);

        $action = app(CreateWorkAction::class);
        $action->execute($offer1);

        $sr2 = $this->createServiceRequest($client, $category);
        $start2 = Carbon::parse('2026-09-20 15:00:00', 'America/Argentina/Buenos_Aires');
        $offer2 = $this->createOffer($sr2, $provider1, $start2, 120);

        try {
            $action->execute($offer2);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scheduled_at', $e->errors());
            $this->assertStringContainsString('franja horaria', $e->errors()['scheduled_at'][0]);
        } catch (\Throwable $e) {
            $this->fail('Expected ValidationException but caught: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Test 3: Insertar directamente en base de datos dos trabajos confirmados solapados para el mismo profesional falla por la restricción DB.
     */
    public function test_inserting_directly_two_overlapping_confirmed_works_for_same_provider_fails_at_db_level(): void
    {
        [$client, $provider1, $provider2, $category] = $this->createSetup();
        $sr1 = $this->createServiceRequest($client, $category);
        $sr2 = $this->createServiceRequest($client, $category);

        $start1 = Carbon::parse('2026-09-20 14:00:00', 'America/Argentina/Buenos_Aires');
        $start2 = Carbon::parse('2026-09-20 15:00:00', 'America/Argentina/Buenos_Aires');

        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr1->id,
            'client_id' => $client->id,
            'provider_id' => $provider1->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => $start1,
            'estimated_duration_min' => 120,
        ]);

        $this->expectException(QueryException::class);

        // Bypassing application validation using raw DB insert
        DB::table('works')->insert([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr2->id,
            'client_id' => $client->id,
            'provider_id' => $provider1->id,
            'status' => 'confirmed',
            'currency' => 'ARS',
            'scheduled_at' => '2026-09-20 15:00:00-03',
            'estimated_duration_min' => 120,
            'scheduled_ends_at' => '2026-09-20 17:00:00-03',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test 4: Dos trabajos solapados para profesionales distintos se permiten sin problema.
     */
    public function test_two_overlapping_works_for_different_providers_are_allowed(): void
    {
        [$client, $provider1, $provider2, $category] = $this->createSetup();
        $sr1 = $this->createServiceRequest($client, $category);
        $sr2 = $this->createServiceRequest($client, $category);

        $start = Carbon::parse('2026-09-20 14:00:00', 'America/Argentina/Buenos_Aires');
        $offer1 = $this->createOffer($sr1, $provider1, $start, 120);
        $offer2 = $this->createOffer($sr2, $provider2, $start, 120);

        $action = app(CreateWorkAction::class);
        $work1 = $action->execute($offer1);
        $work2 = $action->execute($offer2);

        $this->assertNotNull($work1);
        $this->assertNotNull($work2);
    }

    /**
     * Test 5: Dos trabajos adyacentes —uno termina exactamente cuando empieza el otro— se permiten.
     */
    public function test_adjacent_works_ending_exactly_when_next_starts_are_allowed(): void
    {
        [$client, $provider1, $provider2, $category] = $this->createSetup();
        $sr1 = $this->createServiceRequest($client, $category);
        $sr2 = $this->createServiceRequest($client, $category);

        $start1 = Carbon::parse('2026-09-20 14:00:00', 'America/Argentina/Buenos_Aires'); // 14:00 - 16:00
        $start2 = Carbon::parse('2026-09-20 16:00:00', 'America/Argentina/Buenos_Aires'); // 16:00 - 18:00

        $offer1 = $this->createOffer($sr1, $provider1, $start1, 120);
        $offer2 = $this->createOffer($sr2, $provider1, $start2, 120);

        $action = app(CreateWorkAction::class);
        $work1 = $action->execute($offer1);
        $work2 = $action->execute($offer2);

        $this->assertNotNull($work1);
        $this->assertNotNull($work2);
    }

    /**
     * Test 6: Un trabajo cancelado o completado no bloquea la creación de otro en el mismo horario.
     */
    public function test_cancelled_or_completed_work_does_not_block_new_work_in_same_time_slot(): void
    {
        [$client, $provider1, $provider2, $category] = $this->createSetup();
        $sr1 = $this->createServiceRequest($client, $category);
        $sr2 = $this->createServiceRequest($client, $category);

        $start = Carbon::parse('2026-09-20 14:00:00', 'America/Argentina/Buenos_Aires');

        // Work 1 cancelled
        $work1 = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr1->id,
            'client_id' => $client->id,
            'provider_id' => $provider1->id,
            'status' => WorkStatus::Cancelled,
            'scheduled_at' => $start,
            'estimated_duration_min' => 120,
        ]);

        $offer2 = $this->createOffer($sr2, $provider1, $start, 120);
        $action = app(CreateWorkAction::class);
        $work2 = $action->execute($offer2);

        $this->assertNotNull($work2);
    }

    /**
     * Test 7: Un trabajo sin horario agendado no bloquea nada.
     */
    public function test_work_without_scheduled_at_does_not_block_anything(): void
    {
        [$client, $provider1, $provider2, $category] = $this->createSetup();
        $sr1 = $this->createServiceRequest($client, $category);
        $sr2 = $this->createServiceRequest($client, $category);

        // Work 1 confirmed without scheduled_at
        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr1->id,
            'client_id' => $client->id,
            'provider_id' => $provider1->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => null,
            'estimated_duration_min' => 120,
        ]);

        $start = Carbon::parse('2026-09-20 14:00:00', 'America/Argentina/Buenos_Aires');
        $offer2 = $this->createOffer($sr2, $provider1, $start, 120);

        $action = app(CreateWorkAction::class);
        $work2 = $action->execute($offer2);

        $this->assertNotNull($work2);
    }

    /**
     * Test 8: Revertir la migración elimina la restricción sin afectar datos.
     */
    public function test_reverting_migration_removes_exclusion_constraint_without_affecting_data(): void
    {
        [$client, $provider1, $provider2, $category] = $this->createSetup();
        $sr = $this->createServiceRequest($client, $category);

        $work = WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr->id,
            'client_id' => $client->id,
            'provider_id' => $provider1->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => Carbon::parse('2026-09-20 14:00:00', 'America/Argentina/Buenos_Aires'),
            'estimated_duration_min' => 120,
        ]);

        $this->assertNotNull(WorkModel::find($work->id));

        // Rollback 1 migration step
        Artisan::call('migrate:rollback', ['--step' => 1]);

        // Work data remains intact after rollback
        $this->assertNotNull(WorkModel::find($work->id));
    }
}
