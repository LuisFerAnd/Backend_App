<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\SoapEvaluation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SoapEvaluationFactory
{
    public function firstOrCreate(Consultation $consultation, User $user): SoapEvaluation
    {
        if ($existing = SoapEvaluation::where('consultation_id', $consultation->id)->first()) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($consultation, $user): SoapEvaluation {
                    if ($existing = SoapEvaluation::where('consultation_id', $consultation->id)->lockForUpdate()->first()) {
                        return $existing;
                    }

                    $date = now()->timezone(config('app.timezone'))->toDateString();
                    $prefix = 'C-'.now()->timezone(config('app.timezone'))->format('d-m-Y').'-';
                    $last = SoapEvaluation::whereDate('test_date', $date)->lockForUpdate()->max('test_code');
                    $sequence = $last ? ((int) substr($last, -3)) + 1 : 1;
                    $vitalSigns = $consultation->vital_signs ?? [];

                    return SoapEvaluation::create([
                        'consultation_id' => $consultation->id,
                        'processing_attempt_id' => $consultation->currentProcessingAttempt?->id,
                        'evaluator_id' => $user->id,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                        'test_code' => $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                        'test_date' => $consultation->consulted_at?->toDateString() ?? $date,
                        'evaluator_name' => $user->name,
                        'evaluator_specialization' => $user->specialization,
                        'audio_duration_seconds' => data_get($vitalSigns, 'audio_duration_seconds'),
                        'ai_time_seconds' => $consultation->processing_time_seconds === null
                            ? data_get($vitalSigns, 'ai_generation_seconds')
                            : (int) round((float) $consultation->processing_time_seconds),
                        'consultation_duration_seconds' => data_get($vitalSigns, 'consultation_duration_seconds'),
                        'consultation_duration_source' => data_get($vitalSigns, 'consultation_duration_seconds') !== null ? 'system' : null,
                        'status' => 'pending',
                        'evaluation_result_type' => $consultation->overall_status === 'failed' ? 'technical_failure' : ($consultation->overall_status === 'completed' ? 'successful_soap' : 'pending_processing'),
                        'last_saved_at' => now(),
                    ]);
                }, 3);
            } catch (QueryException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
                usleep(25000 * ($attempt + 1));
            }
        }

        throw new \RuntimeException('No se pudo generar el código de evaluación.');
    }
}
