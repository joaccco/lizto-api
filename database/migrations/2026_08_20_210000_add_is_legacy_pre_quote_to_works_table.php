<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('works', 'is_legacy_pre_quote')) {
            Schema::table('works', function (Blueprint $table) {
                $table->boolean('is_legacy_pre_quote')->default(false)->after('agreed_price');
            });
        }

        if (Schema::hasColumn('works', 'agreed_price') && Schema::hasColumn('works', 'is_legacy_pre_quote')) {
            DB::statement("UPDATE works SET is_legacy_pre_quote = true WHERE (agreed_price IS NOT NULL AND agreed_price > 0) AND NOT EXISTS (SELECT 1 FROM work_quotes WHERE work_quotes.work_id = works.id AND work_quotes.status = 'accepted')");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('works', 'is_legacy_pre_quote')) {
            Schema::table('works', function (Blueprint $table) {
                $table->dropColumn('is_legacy_pre_quote');
            });
        }
    }
};
