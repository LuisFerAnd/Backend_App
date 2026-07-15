<?php

namespace App\Services;

class SoapEvaluationCalculator
{
    public function __construct(private readonly ProcessingTimeService $processingTime) {}

    public const BINARY = ['use_prototype', 'audio_transcription', 'clinical_processing', 'soap_generation'];

    public const SOAP = ['soap_subjective', 'soap_objective', 'soap_assessment', 'soap_plan', 'soap_placement', 'soap_clarity'];

    public const ERRORS = ['error_transcription', 'error_omission', 'error_added', 'error_confusion', 'error_placement', 'error_wording'];

    public const UTILITY = ['utility_1', 'utility_2', 'utility_3', 'utility_4', 'utility_5', 'utility_6'];

    public const EASE = ['ease_1', 'ease_2', 'ease_3', 'ease_4', 'ease_5', 'ease_6'];

    public function calculate(array $data): array
    {
        if (array_key_exists('manual_time_seconds', $data) && $data['manual_time_seconds'] !== null) {
            [$range, $label] = $this->processingTime->classifyProcessingTime((float) $data['manual_time_seconds']);
            $data['manual_time_range'] = $range;
            $data['manual_time_label'] = $label;

            $prototypeSeconds = $data['prototype_time_seconds'] ?? $data['ai_time_seconds'] ?? null;
            if ($prototypeSeconds !== null) {
                $exactDifference = (float) $data['manual_time_seconds'] - (float) $prototypeSeconds;
                $data['time_difference_seconds_exact'] = round($exactDifference, 3);
                $data['time_difference_seconds'] = (int) round($exactDifference);
            }
        }

        if ($this->allPresent($data, self::SOAP) && collect(self::SOAP)->every(fn (string $key) => in_array((int) $data[$key], [1, 2, 3], true))) {
            $data['soap_total'] = $this->sum($data, self::SOAP);
            $data['soap_max'] = 18;
            $data['soap_percentage'] = round((($data['soap_total'] - 6) / 12) * 100, 2);
        }

        if ($this->allPresent($data, self::ERRORS) && collect(self::ERRORS)->every(fn (string $key) => in_array((int) $data[$key], [1, 2, 3, 4, 5], true))) {
            $values = array_map(fn (string $key) => (int) $data[$key], self::ERRORS);
            $data['error_total'] = array_sum($values);
            $data['error_totally_wrong_count'] = count(array_filter($values, fn (int $value) => $value === 1));
            $data['error_severe_count'] = count(array_filter($values, fn (int $value) => $value === 2));
            $data['error_moderate_count'] = count(array_filter($values, fn (int $value) => $value === 3));
            $data['error_mild_count'] = count(array_filter($values, fn (int $value) => $value === 4));
            $data['error_none_count'] = count(array_filter($values, fn (int $value) => $value === 5));
        }

        foreach ([['keys' => self::UTILITY, 'prefix' => 'utility'], ['keys' => self::EASE, 'prefix' => 'ease']] as $scale) {
            if ($this->allPresent($data, $scale['keys'])) {
                $total = $this->sum($data, $scale['keys']);
                $data[$scale['prefix'].'_total'] = $total;
                $data[$scale['prefix'].'_average'] = round($total / 6, 2);
            }
        }

        return $data;
    }

    public function requiredFields(bool $soapGenerated = true): array
    {
        $base = [...self::BINARY, ...self::UTILITY, ...self::EASE];

        return $soapGenerated
            ? [...$base, 'manual_time_seconds', ...self::SOAP, ...self::ERRORS]
            : [...$base, 'manual_time_seconds'];
    }

    private function allPresent(array $data, array $keys): bool
    {
        return collect($keys)->every(fn (string $key) => array_key_exists($key, $data) && $data[$key] !== null);
    }

    private function sum(array $data, array $keys): int
    {
        return array_sum(array_map(fn (string $key) => (int) $data[$key], $keys));
    }
}
