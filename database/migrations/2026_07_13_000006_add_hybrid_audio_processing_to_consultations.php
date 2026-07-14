<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->string('transcription_strategy', 24)->nullable()->after('transcription_status');
            $table->string('consolidated_audio_path')->nullable()->after('transcription_text');
            $table->unsignedBigInteger('consolidated_audio_size')->nullable()->after('consolidated_audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropColumn([
                'transcription_strategy',
                'consolidated_audio_path',
                'consolidated_audio_size',
            ]);
        });
    }
};
