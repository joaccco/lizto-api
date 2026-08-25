<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->timestampTz('scheduled_ends_at')->nullable()->after('scheduled_at');
        });

        DB::statement("
            UPDATE works
            SET scheduled_ends_at = scheduled_at + (COALESCE(estimated_duration_min, 60) || ' minutes')::interval
            WHERE scheduled_at IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn('scheduled_ends_at');
        });
    }
};
