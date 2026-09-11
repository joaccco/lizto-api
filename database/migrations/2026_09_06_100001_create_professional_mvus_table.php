<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('professional_mvus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->unique()->constrained('provider_profiles')->cascadeOnDelete();
            $table->foreignId('identity_id')->nullable()->constrained('identities')->nullOnDelete();

            // Identity verification timestamp
            $table->timestamp('identity_verified_at')->nullable();

            // Antecedentes penales
            $table->timestamp('antecedentes_cert_uploaded_at')->nullable();
            $table->string('antecedentes_cert_url', 2048)->nullable(); // S3 signed URL
            $table->enum('antecedentes_status', ['pending', 'approved', 'rejected'])->default('pending');

            // Matrícula profesional
            $table->text('matrícula_number')->nullable(); // Encrypted
            $table->timestamp('matrícula_verified_at')->nullable();

            // Manual admin verification
            $table->boolean('skills_verified')->default(false);
            $table->foreignId('skills_verified_by')->nullable()->constrained('users')->nullOnDelete();

            // Overall state
            $table->enum('overall_verification_status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->timestamps();

            $table->index('overall_verification_status');
        });

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->boolean('is_migrated')->default(false)->after('is_verified');
            $table->timestamp('migrated_at')->nullable()->after('is_migrated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropColumn(['is_migrated', 'migrated_at']);
        });

        Schema::dropIfExists('professional_mvus');
    }
};
