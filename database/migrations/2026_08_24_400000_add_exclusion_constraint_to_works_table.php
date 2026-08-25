<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Enable btree_gist extension required for EXCLUDE USING gist on scalar + range types
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist;');

        // 2. Check for pre-existing conflicting records
        $conflicts = DB::select("
            SELECT w1.id AS id1, w1.uuid AS uuid1, w1.provider_id, w1.scheduled_at AS start1, w1.scheduled_ends_at AS end1,
                   w2.id AS id2, w2.uuid AS uuid2, w2.scheduled_at AS start2, w2.scheduled_ends_at AS end2
            FROM works w1
            JOIN works w2 ON w1.provider_id = w2.provider_id AND w1.id < w2.id
            WHERE w1.status IN ('confirmed', 'in_progress')
              AND w2.status IN ('confirmed', 'in_progress')
              AND w1.scheduled_at IS NOT NULL AND w1.scheduled_ends_at IS NOT NULL
              AND w2.scheduled_at IS NOT NULL AND w2.scheduled_ends_at IS NOT NULL
              AND tstzrange(w1.scheduled_at, w1.scheduled_ends_at, '[)') && tstzrange(w2.scheduled_at, w2.scheduled_ends_at, '[)')
        ");

        if (count($conflicts) > 0) {
            echo "[MIGRATION LOG] Se encontraron " . count($conflicts) . " parejas de trabajos en conflicto de horario:\n";
            foreach ($conflicts as $c) {
                echo "[MIGRATION LOG] Conflicto Provider ID: {$c->provider_id} | Work 1: {$c->uuid1} ({$c->start1} - {$c->end1}) vs Work 2: {$c->uuid2} ({$c->start2} - {$c->end2})\n";

                // Resolve pre-existing conflict by marking the second work as cancelled to allow constraint creation
                DB::table('works')->where('id', $c->id2)->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);
                echo "[MIGRATION LOG] Resuelto: Se canceló el trabajo duplicate/secundario ID {$c->id2} ({$c->uuid2})\n";
            }
        } else {
            echo "[MIGRATION LOG] No se encontraron trabajos en conflicto en la base de datos actual.\n";
        }

        // 3. Add exclusion constraint to guarantee no overlapping confirmed/in_progress works for same provider
        DB::statement("
            ALTER TABLE works
            ADD CONSTRAINT no_overlapping_provider_schedule
            EXCLUDE USING gist (
                provider_id WITH =,
                tstzrange(scheduled_at, scheduled_ends_at, '[)') WITH &&
            )
            WHERE (status IN ('confirmed', 'in_progress') AND scheduled_at IS NOT NULL AND scheduled_ends_at IS NOT NULL);
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE works
            DROP CONSTRAINT IF EXISTS no_overlapping_provider_schedule;
        ");
    }
};
