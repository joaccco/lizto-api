<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->index(['category_id', 'sort_order'], 'idx_survey_questions_category_sort');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->index('client_id', 'idx_service_requests_client');
            $table->index('status', 'idx_service_requests_status');
            $table->index('category_id', 'idx_service_requests_category');
        });

        Schema::table('match_sessions', function (Blueprint $table) {
            $table->index('service_request_id', 'idx_match_sessions_request');
        });

        Schema::table('match_cards', function (Blueprint $table) {
            $table->index(['match_session_id', 'card_status'], 'idx_match_cards_session_status');
        });
    }

    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->dropIndex('idx_survey_questions_category_sort');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex('idx_service_requests_client');
            $table->dropIndex('idx_service_requests_status');
            $table->dropIndex('idx_service_requests_category');
        });

        Schema::table('match_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_match_sessions_request');
        });

        Schema::table('match_cards', function (Blueprint $table) {
            $table->dropIndex('idx_match_cards_session_status');
        });
    }
};
