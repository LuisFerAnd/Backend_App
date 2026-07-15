<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('consultations')
            ->select('id')
            ->orderBy('id')
            ->each(function ($consultation): void {
                $duration = (int) DB::table('consultation_audio_segments')
                    ->where('consultation_id', $consultation->id)
                    ->sum('duration_seconds');
                if ($duration <= 0) {
                    return;
                }

                DB::table('consultations')->where('id', $consultation->id)->update([
                    'recording_duration_seconds' => $duration,
                ]);
                DB::table('soap_evaluations')->where('consultation_id', $consultation->id)->update([
                    'audio_duration_seconds' => $duration,
                ]);
            });
    }

    public function down(): void
    {
        // The original values cannot be reconstructed safely.
    }
};
