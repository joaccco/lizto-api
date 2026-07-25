<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('bio')->nullable();
            $table->smallInteger('years_experience')->default(0);
            $table->boolean('is_verified')->default(false);
            $table->jsonb('verification_docs')->nullable();
            $table->decimal('avg_rating', 3, 2)->default(0);
            $table->integer('total_reviews')->default(0);
            $table->integer('total_jobs_completed')->default(0);
            $table->decimal('completion_rate', 5, 2)->default(100);
            $table->integer('cancellation_count')->default(0);
            $table->decimal('response_rate', 5, 2)->default(100);
            $table->integer('avg_response_minutes')->default(60);
            $table->decimal('base_lat', 10, 8)->nullable();
            $table->decimal('base_lng', 11, 8)->nullable();
            $table->string('base_address', 500)->nullable();
            $table->string('availability_status', 20)->default('unavailable');
            $table->timestampTz('busy_until')->nullable();
            $table->timestampTz('next_available_at')->nullable();
            $table->decimal('current_job_lat', 10, 8)->nullable();
            $table->decimal('current_job_lng', 11, 8)->nullable();
            $table->timestampsTz();
            $table->index('availability_status', 'idx_provider_status');
            $table->index(['base_lat', 'base_lng'], 'idx_provider_location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_profiles');
    }
};