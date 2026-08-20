<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('status', 30)->default('draft')->after('user_id');
            $table->string('first_name', 100)->nullable()->after('status');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('commercial_name', 150)->nullable()->after('last_name');

            $table->timestampTz('submitted_at')->nullable()->after('verification_docs');
            $table->timestampTz('verified_at')->nullable()->after('submitted_at');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('rejected_at')->nullable()->after('verified_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->timestampTz('suspended_at')->nullable()->after('rejection_reason');
            $table->text('suspension_reason')->nullable()->after('suspended_at');

            $table->index('status', 'idx_provider_profiles_status');
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropIndex('idx_provider_profiles_status');
            $table->dropForeign(['verified_by']);
            $table->dropColumn([
                'uuid',
                'status',
                'first_name',
                'last_name',
                'commercial_name',
                'submitted_at',
                'verified_at',
                'verified_by',
                'rejected_at',
                'rejection_reason',
                'suspended_at',
                'suspension_reason',
            ]);
        });
    }
};
