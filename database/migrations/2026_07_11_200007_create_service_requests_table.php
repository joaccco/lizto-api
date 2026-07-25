<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->text('raw_prompt');
            $table->jsonb('parsed_intent')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('structured_data')->default(DB::raw("'{}'::jsonb"));
            $table->decimal('location_lat', 10, 8)->nullable();
            $table->decimal('location_lng', 11, 8)->nullable();
            $table->string('location_address', 500)->nullable();
            $table->boolean('is_remote')->default(false);
            $table->string('urgency', 20)->default('scheduled');
            $table->timestampTz('preferred_datetime')->nullable();
            $table->string('status', 30)->default('pending_survey');
            $table->timestampTz('expires_at')->default(DB::raw("NOW() + interval '24 hours'"));
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};