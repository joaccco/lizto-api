<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('always_requires_evaluation')->default(false);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('questionnaire_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained('service_types')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(['service_type_id', 'version_number']);
        });

        Schema::table('service_types', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('questionnaire_versions')->nullOnDelete();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_version_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $table->string('question_key');
            $table->text('question_text');
            $table->enum('input_type', ['single_select', 'multi_select', 'boolean', 'short_text', 'number', 'date'])->default('single_select');
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('position')->default(1);
            $table->string('question_group')->nullable();
            $table->boolean('used_for_matching')->default(false);
            $table->boolean('used_for_quote')->default(false);
            $table->boolean('is_critical')->default(false);
            $table->timestamps();

            $table->unique(['questionnaire_version_id', 'question_key']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->string('label');
            $table->string('value');
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
        });

        Schema::create('question_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('depends_on_question_id')->constrained('questions')->cascadeOnDelete();
            $table->string('depends_on_question_key');
            $table->enum('operator', ['equals', 'not_equals', 'contains', 'in'])->default('equals');
            $table->json('expected_value');
            $table->timestamps();
        });

        Schema::create('request_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained('service_requests')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->string('question_key');
            $table->json('answer_value');
            $table->enum('source', ['user', 'ai_extracted', 'profile', 'system', 'professional'])->default('user');
            $table->float('ai_confidence')->nullable();
            $table->boolean('confirmed_by_user')->default(false);
            $table->timestamps();

            $table->unique(['service_request_id', 'question_id']);
        });

        Schema::create('service_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->unique()->constrained('service_requests')->cascadeOnDelete();
            $table->text('summary');
            $table->json('attributes');
            $table->boolean('is_confirmed')->default(false);
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('service_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('service_requests', 'original_prompt')) {
                $table->text('original_prompt')->nullable()->after('raw_prompt');
            }
            if (!Schema::hasColumn('service_requests', 'service_type_id')) {
                $table->foreignId('service_type_id')->nullable()->constrained('service_types')->nullOnDelete()->after('category_id');
            }
            if (!Schema::hasColumn('service_requests', 'questionnaire_version_id')) {
                $table->foreignId('questionnaire_version_id')->nullable()->constrained('questionnaire_versions')->nullOnDelete()->after('service_type_id');
            }
            if (!Schema::hasColumn('service_requests', 'quote_readiness')) {
                $table->enum('quote_readiness', [
                    'insufficient_information',
                    'ready_for_match',
                    'ready_for_quote',
                    'requires_professional_evaluation'
                ])->default('insufficient_information')->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (Schema::hasColumn('service_requests', 'quote_readiness')) {
                $table->dropColumn('quote_readiness');
            }
            if (Schema::hasColumn('service_requests', 'questionnaire_version_id')) {
                $table->dropForeign(['questionnaire_version_id']);
                $table->dropColumn('questionnaire_version_id');
            }
            if (Schema::hasColumn('service_requests', 'service_type_id')) {
                $table->dropForeign(['service_type_id']);
                $table->dropColumn('service_type_id');
            }
            if (Schema::hasColumn('service_requests', 'original_prompt')) {
                $table->dropColumn('original_prompt');
            }
        });

        Schema::dropIfExists('service_briefs');
        Schema::dropIfExists('request_answers');
        Schema::dropIfExists('question_conditions');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        
        Schema::dropIfExists('questionnaire_versions');
        Schema::dropIfExists('service_types');
    }
};
