<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('provider_profiles')->onDelete('cascade');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_meters')->default(10);
            $table->unsignedSmallInteger('heading')->nullable(); // 0 to 359
            $table->decimal('speed_kmh', 5, 2)->nullable(); // 0 to 200 km/h
            $table->timestamps();

            // Composite index for ultra-fast latest location lookup per provider
            $table->index(['provider_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_locations');
    }
};
