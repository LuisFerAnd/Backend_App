<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consultations', 'consultation_code')) {
            Schema::table('consultations', function (Blueprint $table): void {
                $table->string('consultation_code', 40)->nullable()->unique()->after('id');
                $table->string('local_consultation_code', 50)->nullable()->after('consultation_code');
                $table->timestamp('started_at')->nullable()->after('consulted_at');
                $table->timestamp('finished_at')->nullable()->after('started_at');
                $table->unsignedInteger('recording_duration_seconds')->default(0)->after('finished_at');
                $table->string('upload_status', 32)->default('not_started')->after('recording_status');
                $table->string('transcription_status', 32)->default('not_started')->after('upload_status');
                $table->string('pdf_status', 32)->default('not_generated')->after('soap_status');
                $table->string('evaluation_status', 32)->default('pending')->after('pdf_status');
                $table->string('overall_status', 32)->default('created')->after('evaluation_status');
                $table->string('failure_stage', 40)->nullable()->after('overall_status');
                $table->string('failure_code', 100)->nullable()->after('failure_stage');
                $table->text('failure_message')->nullable()->after('failure_code');
                $table->text('user_friendly_error_message')->nullable()->after('failure_message');
                $table->timestamp('failure_occurred_at')->nullable()->after('user_friendly_error_message');
                $table->boolean('is_evaluable')->default(true)->after('failure_occurred_at');
                $table->unsignedInteger('last_processing_attempt')->default(1)->after('is_evaluable');
                $table->boolean('created_offline')->default(false)->after('last_processing_attempt');
                $table->timestamp('synced_at')->nullable()->after('created_offline');

                $table->index(['doctor_id', 'overall_status']);
                $table->index('failure_stage');
            });
        }

        DB::table('consultations')->whereNull('consultation_code')->orderBy('id')->each(function ($consultation): void {
            $date = date('d-m-Y', strtotime($consultation->consulted_at));
            DB::table('consultations')->where('id', $consultation->id)->update([
                'consultation_code' => 'C-'.$date.'-'.str_pad((string) $consultation->id, 6, '0', STR_PAD_LEFT),
                'started_at' => $consultation->consulted_at,
                'finished_at' => $consultation->recording_finished_at,
                'upload_status' => 'completed',
                'transcription_status' => $consultation->transcription_text ? 'completed' : 'not_started',
                'overall_status' => $consultation->processing_status === 'completed' ? 'completed' : $consultation->processing_status,
            ]);
        });

        if (! Schema::hasTable('consultation_processing_attempts')) {
            Schema::create('consultation_processing_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('attempt_number');
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->string('result', 32)->default('pending');
                $table->string('failure_stage', 40)->nullable();
                $table->string('failure_code', 100)->nullable();
                $table->text('failure_message')->nullable();
                $table->unsignedInteger('segments_received')->default(0);
                $table->unsignedInteger('segments_transcribed')->default(0);
                $table->boolean('soap_generated')->default(false);
                $table->boolean('pdf_generated')->default(false);
                $table->timestamps();
                $table->unique(['consultation_id', 'attempt_number'], 'consult_attempt_number_unique');
            });
        } elseif (! Schema::hasIndex('consultation_processing_attempts', 'consult_attempt_number_unique')) {
            Schema::table('consultation_processing_attempts', function (Blueprint $table): void {
                $table->unique(['consultation_id', 'attempt_number'], 'consult_attempt_number_unique');
            });
        }

        if (! Schema::hasColumn('soap_evaluations', 'processing_attempt_id')) {
            Schema::table('soap_evaluations', function (Blueprint $table): void {
                $table->foreignId('processing_attempt_id')->nullable()->after('consultation_id')->constrained('consultation_processing_attempts')->nullOnDelete();
                $table->string('evaluation_result_type', 32)->default('pending_processing')->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('soap_evaluations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('processing_attempt_id');
            $table->dropColumn(['evaluation_result_type']);
        });
        Schema::dropIfExists('consultation_processing_attempts');
        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropIndex(['doctor_id', 'overall_status']);
            $table->dropIndex(['failure_stage']);
            $table->dropUnique(['consultation_code']);
            $table->dropColumn(['consultation_code', 'local_consultation_code', 'started_at', 'finished_at', 'recording_duration_seconds', 'upload_status', 'transcription_status', 'pdf_status', 'evaluation_status', 'overall_status', 'failure_stage', 'failure_code', 'failure_message', 'user_friendly_error_message', 'failure_occurred_at', 'is_evaluable', 'last_processing_attempt', 'created_offline', 'synced_at']);
        });
    }
};
