<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOAP_COLUMNS = [
        'soap_subjective',
        'soap_objective',
        'soap_assessment',
        'soap_plan',
        'soap_placement',
        'soap_clarity',
    ];

    public function up(): void
    {
        $this->migrateValues([0 => 1, 1 => 2, 2 => 3], true);

        Schema::table('soap_evaluations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('soap_max')->default(18)->change();
        });
    }

    public function down(): void
    {
        $this->migrateValues([1 => 0, 2 => 1, 3 => 2], false);

        Schema::table('soap_evaluations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('soap_max')->default(12)->change();
        });
    }

    private function migrateValues(array $mapping, bool $newScale): void
    {
        DB::table('soap_evaluations')->orderBy('id')->chunkById(100, function ($evaluations) use ($mapping, $newScale): void {
            foreach ($evaluations as $evaluation) {
                $values = [];
                foreach (self::SOAP_COLUMNS as $column) {
                    $current = $evaluation->{$column};
                    $values[$column] = $current !== null && array_key_exists((int) $current, $mapping)
                        ? $mapping[(int) $current]
                        : $current;
                }

                $validScale = $newScale ? [1, 2, 3] : [0, 1, 2];
                $scored = array_values(array_filter(
                    $values,
                    fn ($value): bool => $value !== null && in_array((int) $value, $validScale, true)
                ));

                $values['soap_max'] = $newScale ? 18 : 12;
                if (count($scored) === count(self::SOAP_COLUMNS)) {
                    $total = array_sum(array_map('intval', $scored));
                    $values['soap_total'] = $total;
                    $values['soap_percentage'] = $newScale
                        ? round((($total - 6) / 12) * 100, 2)
                        : round(($total / 12) * 100, 2);
                }

                DB::table('soap_evaluations')->where('id', $evaluation->id)->update($values);
            }
        });
    }
};
