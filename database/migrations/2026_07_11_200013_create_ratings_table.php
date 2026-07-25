<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_id')->constrained('users')->cascadeOnDelete();
            $table->string('direction', 20);
            $table->smallInteger('score');
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['work_id', 'reviewer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};