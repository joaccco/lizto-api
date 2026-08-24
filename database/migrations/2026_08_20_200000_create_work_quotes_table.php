<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('work_quotes')) {
            Schema::create('work_quotes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('work_id')->constrained('works')->onDelete('cascade');
                $table->foreignId('provider_id')->constrained('provider_profiles')->onDelete('cascade');
                $table->foreignId('client_id')->constrained('users')->onDelete('cascade');
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3)->default('ARS');
                $table->json('breakdown_items')->nullable();
                $table->integer('estimated_hours')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->text('terms_conditions')->nullable();
                $table->string('origin')->default('manual'); // manual, offer_acceptance, final_quote_confirmation
                $table->string('status')->default('pending'); // pending, accepted, rejected, revision_requested
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();

                $table->index(['work_id', 'status']);
            });
        }

        if (!Schema::hasColumn('works', 'agreed_price')) {
            Schema::table('works', function (Blueprint $table) {
                $table->decimal('agreed_price', 12, 2)->nullable()->after('estimated_duration_min');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_quotes');
    }
};
