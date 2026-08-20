<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained('provider_profiles')->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->jsonb('media_urls')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['provider_id', 'sort_order'], 'idx_portfolio_provider_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_portfolio_items');
    }
};
