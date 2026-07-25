<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_session_id')->constrained('match_sessions')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('provider_profiles')->cascadeOnDelete();
            $table->integer('rank_position');
            $table->decimal('score_total', 5, 4);
            $table->jsonb('score_breakdown')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('snapshot')->default(DB::raw("'{}'::jsonb"));
            $table->string('card_status', 20)->default('pending');
            $table->timestampTz('shown_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['match_session_id', 'provider_id']);
            $table->index(['match_session_id', 'rank_position'], 'idx_match_cards_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_cards');
    }
};