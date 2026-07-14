<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_audio_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_uuid');
            $table->unsignedInteger('segment_number');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum', 64);
            $table->string('upload_status', 24)->default('uploaded');
            $table->string('transcription_status', 24)->default('pending');
            $table->longText('transcription_text')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->text('error_message')->nullable();
            $table->boolean('is_final')->default(false);
            $table->timestamps();

            $table->unique(['session_uuid', 'segment_number']);
            $table->index('consultation_id');
            $table->index('session_uuid');
            $table->index('upload_status');
            $table->index('transcription_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_audio_segments');
    }
};
