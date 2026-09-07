<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('works') && !Schema::hasColumn('works', 'fake_data_source')) {
            Schema::table('works', function (Blueprint $table) {
                $table->string('fake_data_source', 50)->nullable()->index()->after('status');
            });
        }

        if (Schema::hasTable('provider_documents')) {
            Schema::table('provider_documents', function (Blueprint $table) {
                if (!Schema::hasColumn('provider_documents', 'expiry_date')) {
                    $table->date('expiry_date')->nullable()->after('document_number');
                }
                if (!Schema::hasColumn('provider_documents', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('status');
                }
                if (!Schema::hasColumn('provider_documents', 'verified_at')) {
                    $table->timestampTz('verified_at')->nullable()->after('rejection_reason');
                }
                if (!Schema::hasColumn('provider_documents', 'rejected_at')) {
                    $table->timestampTz('rejected_at')->nullable()->after('verified_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('works') && Schema::hasColumn('works', 'fake_data_source')) {
            Schema::table('works', function (Blueprint $table) {
                $table->dropColumn('fake_data_source');
            });
        }

        if (Schema::hasTable('provider_documents')) {
            Schema::table('provider_documents', function (Blueprint $table) {
                $columnsToDrop = [];
                foreach (['expiry_date', 'rejection_reason', 'verified_at', 'rejected_at'] as $col) {
                    if (Schema::hasColumn('provider_documents', $col)) {
                        $columnsToDrop[] = $col;
                    }
                }
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
