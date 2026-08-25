<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->index(
                ['provider_id', 'status', 'scheduled_at', 'scheduled_ends_at'],
                'idx_works_schedule_overlap'
            );
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropIndex('idx_works_schedule_overlap');
        });
    }
};
