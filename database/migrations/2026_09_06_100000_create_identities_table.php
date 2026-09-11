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
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            // Personal data (encrypted in storage per Ley 25.326)
            $table->text('dni')->nullable()->unique();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->text('birthdate')->nullable();

            // Biometrics
            $table->text('face_template')->nullable(); // JSON embedding / encrypted
            $table->string('face_photo_hash')->nullable(); // Audit hash only, raw photo not stored

            // Signed URLs (S3, 1-hour expiry)
            $table->string('document_front_url', 2048)->nullable();
            $table->string('selfie_url', 2048)->nullable();

            // Verification details
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by', 100)->nullable(); // 'didit_id' | 'manual_admin'
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('rejection_reason', 500)->nullable();

            // External Didit reference
            $table->string('didit_kyc_response_id', 255)->nullable()->index();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
