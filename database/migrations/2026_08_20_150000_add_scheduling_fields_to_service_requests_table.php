<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable()->after('preferred_datetime');
            $table->string('window_start', 10)->nullable()->after('scheduled_date');
            $table->string('window_end', 10)->nullable()->after('window_start');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['scheduled_date', 'window_start', 'window_end']);
        });
    }
};
