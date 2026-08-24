<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Resolve pre-existing data violations if any exist
        $violatingWorks = DB::table('work_quotes')
            ->select('work_id')
            ->where('status', 'accepted')
            ->groupBy('work_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('work_id');

        foreach ($violatingWorks as $workId) {
            $latestId = DB::table('work_quotes')
                ->where('work_id', $workId)
                ->where('status', 'accepted')
                ->orderByDesc('accepted_at')
                ->orderByDesc('id')
                ->value('id');

            DB::table('work_quotes')
                ->where('work_id', $workId)
                ->where('status', 'accepted')
                ->where('id', '!=', $latestId)
                ->update(['status' => 'rejected']);
        }

        // 2. Add PostgreSQL Partial Unique Index
        DB::statement("
            CREATE UNIQUE INDEX work_quotes_accepted_work_id_unique
            ON work_quotes (work_id)
            WHERE status = 'accepted'
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS work_quotes_accepted_work_id_unique");
    }
};
