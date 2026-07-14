<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->uuid('session_uuid')->nullable()->unique()->after('patient_id');
            $table->string('recording_status', 32)->default('completed')->after('session_uuid');
            $table->string('processing_status', 32)->default('completed')->after('recording_status');
            $table->unsignedInteger('expected_segments')->nullable()->after('processing_status');
            $table->unsignedInteger('received_segments')->default(0)->after('expected_segments');
            $table->unsignedInteger('transcribed_segments')->default(0)->after('received_segments');
            $table->timestamp('recording_finished_at')->nullable()->after('transcribed_segments');
            $table->longText('transcription_text')->nullable()->after('recording_finished_at');
            $table->string('soap_status', 32)->default('completed')->after('transcription_text');
            $table->text('soap_error')->nullable()->after('soap_status');

            $table->index(['doctor_id', 'processing_status']);
            $table->index(['recording_status', 'processing_status']);
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropIndex(['doctor_id', 'processing_status']);
            $table->dropIndex(['recording_status', 'processing_status']);
            $table->dropUnique(['session_uuid']);
            $table->dropColumn([
                'session_uuid',
                'recording_status',
                'processing_status',
                'expected_segments',
                'received_segments',
                'transcribed_segments',
                'recording_finished_at',
                'transcription_text',
                'soap_status',
                'soap_error',
            ]);
        });
    }
};
