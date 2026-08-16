<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropUnique(['work_id', 'reviewer_id']);
            $table->unique(['work_id', 'reviewer_id', 'direction'], 'ratings_work_reviewer_direction_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropUnique('ratings_work_reviewer_direction_unique');
            $table->unique(['work_id', 'reviewer_id']);
        });
    }
};
