<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->bigInteger('price_min')->nullable()->after('proposed_price');
            $table->bigInteger('price_max')->nullable()->after('price_min');
            $table->text('notes')->nullable()->after('estimated_duration_min');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['price_min', 'price_max', 'notes']);
        });
    }
};
