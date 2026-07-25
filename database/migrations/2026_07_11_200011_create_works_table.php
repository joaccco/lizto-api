<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('service_request_id')->constrained('service_requests')->cascadeOnDelete();
            $table->foreignId('match_card_id')->constrained('match_cards')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('provider_profiles')->cascadeOnDelete();
            $table->string('status', 30)->default('pending_confirmation');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->integer('estimated_duration_min')->nullable();
            $table->timestampTz('estimated_completion_at')->nullable();
            $table->decimal('work_lat', 10, 8)->nullable();
            $table->decimal('work_lng', 11, 8)->nullable();
            $table->string('work_address', 500)->nullable();
            $table->decimal('agreed_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('ARS');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('works');
    }
};