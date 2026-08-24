<?php

namespace Tests\Feature;

use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\AgendaSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeederAndMigrationCohesionTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_generated_by_agenda_seeder_can_be_completed(): void
    {
        $this->seed(DatabaseSeeder::class);

        /** @var WorkModel $work */
        $work = WorkModel::whereIn('status', [WorkStatus::Confirmed->value, WorkStatus::InProgress->value])->first();
        $this->assertNotNull($work, 'Precondición: debe existir al menos un trabajo en estado confirmed o in_progress sembrado.');

        $providerUser = $work->provider->user;

        $response = $this->actingAs($providerUser, 'sanctum')
            ->postJson("/api/v1/works/{$work->uuid}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->assertEquals(WorkStatus::Completed->value, $work->fresh()->status->value);
    }

    public function test_migrating_fresh_db_creates_is_legacy_pre_quote_via_new_migration(): void
    {
        $this->assertTrue(Schema::hasTable('work_quotes'));
        $this->assertTrue(Schema::hasColumn('works', 'agreed_price'));
        $this->assertTrue(Schema::hasColumn('works', 'is_legacy_pre_quote'));
    }

    public function test_rolling_back_legacy_migration_drops_is_legacy_pre_quote_and_preserves_work_quotes_and_agreed_price(): void
    {
        $migration = include database_path('migrations/2026_08_20_210000_add_is_legacy_pre_quote_to_works_table.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('works', 'is_legacy_pre_quote'));
        $this->assertTrue(Schema::hasColumn('works', 'agreed_price'));
        $this->assertTrue(Schema::hasTable('work_quotes'));

        // Re-run migration to leave DB clean
        $migration->up();
        $this->assertTrue(Schema::hasColumn('works', 'is_legacy_pre_quote'));
    }
}
