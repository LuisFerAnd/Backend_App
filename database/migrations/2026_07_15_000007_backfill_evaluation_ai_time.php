<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('soap_evaluations')
            ->join('consultations', 'consultations.id', '=', 'soap_evaluations.consultation_id')
            ->whereNull('soap_evaluations.ai_time_seconds')
            ->whereNotNull('consultations.processing_time_seconds')
            ->select('soap_evaluations.id', 'consultations.processing_time_seconds')
            ->orderBy('soap_evaluations.id')
            ->each(function ($evaluation): void {
                DB::table('soap_evaluations')->where('id', $evaluation->id)->update([
                    'ai_time_seconds' => (int) round((float) $evaluation->processing_time_seconds),
                ]);
            });
    }

    public function down(): void
    {
        // Backfilled research values must not be erased on rollback.
    }
};
