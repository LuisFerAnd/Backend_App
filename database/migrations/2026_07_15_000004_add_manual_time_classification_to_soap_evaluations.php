<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('soap_evaluations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('manual_time_range')->nullable()->after('manual_time_seconds');
            $table->string('manual_time_label', 32)->nullable()->after('manual_time_range');
        });

        DB::table('soap_evaluations')
            ->join('consultations', 'consultations.id', '=', 'soap_evaluations.consultation_id')
            ->whereNotNull('consultations.processing_time_seconds')
            ->select('soap_evaluations.id', 'soap_evaluations.manual_time_seconds', 'consultations.processing_time_seconds')
            ->orderBy('soap_evaluations.id')
            ->each(function ($evaluation): void {
                $prototypeSeconds = (float) $evaluation->processing_time_seconds;
                $values = ['ai_time_seconds' => (int) round($prototypeSeconds)];
                if ($evaluation->manual_time_seconds !== null) {
                    [$range, $label] = $this->classify((float) $evaluation->manual_time_seconds);
                    $values += [
                        'manual_time_range' => $range,
                        'manual_time_label' => $label,
                        'time_difference_seconds' => (int) round((float) $evaluation->manual_time_seconds - $prototypeSeconds),
                    ];
                }
                DB::table('soap_evaluations')->where('id', $evaluation->id)->update($values);
            });
    }

    public function down(): void
    {
        Schema::table('soap_evaluations', function (Blueprint $table): void {
            $table->dropColumn(['manual_time_range', 'manual_time_label']);
        });
    }

    private function classify(float $seconds): array
    {
        return match (true) {
            $seconds <= 30 => [5, 'Muy rápido'],
            $seconds <= 60 => [4, 'Rápido'],
            $seconds <= 120 => [3, 'Moderado'],
            $seconds <= 180 => [2, 'Lento'],
            default => [1, 'Muy lento'],
        };
    }
};
