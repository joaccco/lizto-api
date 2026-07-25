<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained('service_requests')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('survey_questions')->nullOnDelete();
            $table->string('question_key', 100);
            $table->text('question_text');
            $table->jsonb('answer_value');
            $table->boolean('is_ai_generated')->default(false);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};