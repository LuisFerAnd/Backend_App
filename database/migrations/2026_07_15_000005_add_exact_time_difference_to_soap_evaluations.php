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
            $table->decimal('time_difference_seconds_exact', 10, 3)->nullable()->after('time_difference_seconds');
        });

        DB::table('soap_evaluations')
            ->join('consultations', 'consultations.id', '=', 'soap_evaluations.consultation_id')
            ->whereNotNull('soap_evaluations.manual_time_seconds')
            ->whereNotNull('consultations.processing_time_seconds')
            ->select('soap_evaluations.id', 'soap_evaluations.manual_time_seconds', 'consultations.processing_time_seconds')
            ->orderBy('soap_evaluations.id')
            ->each(function ($evaluation): void {
                DB::table('soap_evaluations')->where('id', $evaluation->id)->update([
                    'time_difference_seconds_exact' => number_format(
                        (float) $evaluation->manual_time_seconds - (float) $evaluation->processing_time_seconds,
                        3,
                        '.',
                        ''
                    ),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('soap_evaluations', function (Blueprint $table): void {
            $table->dropColumn('time_difference_seconds_exact');
        });
    }
};
