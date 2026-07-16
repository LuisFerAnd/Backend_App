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
            $table->decimal('time_savings_percentage', 12, 3)->nullable()->after('time_difference_seconds_exact');
            $table->unsignedTinyInteger('time_savings_range')->nullable()->after('time_savings_percentage');
            $table->string('time_savings_label', 32)->nullable()->after('time_savings_range');
        });

        DB::table('soap_evaluations')
            ->leftJoin('consultations', 'consultations.id', '=', 'soap_evaluations.consultation_id')
            ->where('soap_evaluations.manual_time_seconds', '>', 0)
            ->where(function ($query): void {
                $query->whereNotNull('consultations.processing_time_seconds')
                    ->orWhereNotNull('soap_evaluations.ai_time_seconds');
            })
            ->select('soap_evaluations.id', 'soap_evaluations.manual_time_seconds', 'soap_evaluations.ai_time_seconds', 'consultations.processing_time_seconds')
            ->orderBy('soap_evaluations.id')
            ->each(function ($evaluation): void {
                $manualSeconds = (float) $evaluation->manual_time_seconds;
                $prototypeSeconds = (float) ($evaluation->processing_time_seconds ?? $evaluation->ai_time_seconds);
                $percentage = (($manualSeconds - $prototypeSeconds) / $manualSeconds) * 100;
                [$range, $label] = $this->classify($percentage);

                DB::table('soap_evaluations')->where('id', $evaluation->id)->update([
                    'time_savings_percentage' => round($percentage, 3),
                    'time_savings_range' => $range,
                    'time_savings_label' => $label,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('soap_evaluations', function (Blueprint $table): void {
            $table->dropColumn(['time_savings_percentage', 'time_savings_range', 'time_savings_label']);
        });
    }

    /** @return array{0: int, 1: string} */
    private function classify(float $percentage): array
    {
        return match (true) {
            $percentage < -25 => [1, 'Pérdida considerable'],
            $percentage < -5 => [2, 'Pérdida leve'],
            $percentage <= 5 => [3, 'Sin cambio relevante'],
            $percentage <= 25 => [4, 'Ahorro moderado'],
            default => [5, 'Ahorro considerable'],
        };
    }
};
