<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soap_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->string('test_code')->unique();
            $table->date('test_date');
            $table->string('evaluator_name');
            $table->string('evaluator_specialization')->nullable();
            $table->unsignedInteger('consultation_duration_seconds')->nullable();
            $table->string('consultation_duration_source', 12)->nullable();
            $table->unsignedInteger('audio_duration_seconds')->nullable();
            $table->unsignedInteger('ai_time_seconds')->nullable();
            $table->unsignedInteger('manual_time_seconds')->nullable();
            $table->integer('time_difference_seconds')->nullable();

            foreach (['use_prototype', 'audio_transcription', 'clinical_processing', 'soap_generation'] as $column) {
                $table->unsignedTinyInteger($column)->nullable();
            }
            foreach (['soap_subjective', 'soap_objective', 'soap_assessment', 'soap_plan', 'soap_placement', 'soap_clarity'] as $column) {
                $table->unsignedTinyInteger($column)->nullable();
            }
            $table->unsignedTinyInteger('soap_total')->nullable();
            $table->unsignedTinyInteger('soap_max')->default(12);
            $table->decimal('soap_percentage', 5, 2)->nullable();

            foreach (['error_transcription', 'error_omission', 'error_added', 'error_confusion', 'error_placement', 'error_wording'] as $column) {
                $table->unsignedTinyInteger($column)->nullable();
            }
            $table->unsignedTinyInteger('error_total')->nullable();
            $table->unsignedTinyInteger('error_none_count')->nullable();
            $table->unsignedTinyInteger('error_mild_count')->nullable();
            $table->unsignedTinyInteger('error_moderate_count')->nullable();
            $table->unsignedTinyInteger('error_severe_count')->nullable();
            $table->text('error_observations')->nullable();

            for ($i = 1; $i <= 6; $i++) {
                $table->unsignedTinyInteger("utility_$i")->nullable();
                $table->unsignedTinyInteger("ease_$i")->nullable();
            }
            $table->unsignedTinyInteger('utility_total')->nullable();
            $table->decimal('utility_average', 4, 2)->nullable();
            $table->unsignedTinyInteger('ease_total')->nullable();
            $table->decimal('ease_average', 4, 2)->nullable();
            $table->string('status', 12)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('test_date');
            $table->index('status');
            $table->index('evaluator_id');
        });

        Schema::create('soap_evaluation_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('format', 8);
            $table->json('filters')->nullable();
            $table->unsignedInteger('record_count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soap_evaluation_exports');
        Schema::dropIfExists('soap_evaluations');
    }
};
