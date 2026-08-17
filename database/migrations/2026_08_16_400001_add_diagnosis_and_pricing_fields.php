<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->boolean('requires_onsite_diagnosis')->default(false)->after('always_requires_evaluation');
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->enum('pricing_mode', ['quoted', 'requires_visit'])->default('quoted')->after('rejection_reason');
        });

        Schema::table('works', function (Blueprint $table) {
            $table->decimal('final_price', 10, 2)->nullable()->after('agreed_price');
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn('final_price');
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });

        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn('requires_onsite_diagnosis');
        });
    }
};
