<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'observed_failure_stage',
        'observed_error_type',
        'observed_error_description',
        'professional_observations',
        'recording_started',
        'audio_saved',
        'recording_complete',
        'segments_uploaded',
        'error_clearly_shown',
        'consultation_registered',
        'retry_available',
        'workflow_interrupted',
    ];

    public function up(): void
    {
        $existing = array_values(array_filter(
            self::COLUMNS,
            fn (string $column): bool => Schema::hasColumn('soap_evaluations', $column)
        ));
        if ($existing === []) {
            return;
        }

        Schema::table('soap_evaluations', function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }

    public function down(): void
    {
        // Estos campos se retiraron deliberadamente para conservar el instrumento original.
    }
};
