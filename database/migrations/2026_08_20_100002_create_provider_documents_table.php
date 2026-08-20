<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained('provider_profiles')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('document_number', 100)->nullable();
            $table->string('file_path', 500);
            $table->string('status', 30)->default('pending');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['provider_id', 'document_type'], 'idx_provider_doc_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_documents');
    }
};
