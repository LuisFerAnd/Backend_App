<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ERROR_COLUMNS = [
        'error_transcription',
        'error_omission',
        'error_added',
        'error_confusion',
        'error_placement',
        'error_wording',
    ];

    public function up(): void
    {
        Schema::table('soap_evaluations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('error_totally_wrong_count')->nullable()->after('error_total');
        });

        $this->migrateValues([0 => 5, 1 => 4, 2 => 3, 3 => 2], true);
    }

    public function down(): void
    {
        $this->migrateValues([1 => 3, 2 => 3, 3 => 2, 4 => 1, 5 => 0], false);

        Schema::table('soap_evaluations', function (Blueprint $table): void {
            $table->dropColumn('error_totally_wrong_count');
        });
    }

    private function migrateValues(array $mapping, bool $newScale): void
    {
        DB::table('soap_evaluations')->orderBy('id')->chunkById(100, function ($evaluations) use ($mapping, $newScale): void {
            foreach ($evaluations as $evaluation) {
                $values = [];
                foreach (self::ERROR_COLUMNS as $column) {
                    $current = $evaluation->{$column};
                    $values[$column] = $current !== null && array_key_exists((int) $current, $mapping)
                        ? $mapping[(int) $current]
                        : $current;
                }

                $scored = array_values(array_filter(
                    $values,
                    fn ($value): bool => $value !== null && in_array((int) $value, $newScale ? [1, 2, 3, 4, 5] : [0, 1, 2, 3], true)
                ));
                if (count($scored) === count(self::ERROR_COLUMNS)) {
                    $scored = array_map('intval', $scored);
                    $values['error_total'] = array_sum($scored);
                    $values['error_none_count'] = count(array_filter($scored, fn (int $value): bool => $value === ($newScale ? 5 : 0)));
                    $values['error_mild_count'] = count(array_filter($scored, fn (int $value): bool => $value === ($newScale ? 4 : 1)));
                    $values['error_moderate_count'] = count(array_filter($scored, fn (int $value): bool => $value === ($newScale ? 3 : 2)));
                    $values['error_severe_count'] = count(array_filter($scored, fn (int $value): bool => $value === ($newScale ? 2 : 3)));
                    if ($newScale) {
                        $values['error_totally_wrong_count'] = count(array_filter($scored, fn (int $value): bool => $value === 1));
                    }
                }

                DB::table('soap_evaluations')->where('id', $evaluation->id)->update($values);
            }
        });
    }
};
