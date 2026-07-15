<?php

namespace App\Services;

use App\Models\Consultation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ProcessingTimeService
{
    public function startProcessing(Consultation $consultation, ?CarbonInterface $startedAt = null, bool $restart = false): Consultation
    {
        return DB::transaction(function () use ($consultation, $startedAt, $restart): Consultation {
            $locked = Consultation::query()->lockForUpdate()->findOrFail($consultation->id);
            if ($locked->processing_started_at !== null && ! $restart) {
                return $locked;
            }

            $locked->forceFill([
                'processing_started_at' => $startedAt ?? now(),
                'processing_finished_at' => null,
                'processing_time_ms' => null,
                'processing_time_seconds' => null,
                'processing_time_range' => null,
                'processing_time_label' => null,
                'error_code' => null,
                'error_message' => null,
                'error_stage' => null,
                'retry_count' => max(0, (int) $locked->last_processing_attempt - 1),
                'soap_generated' => false,
            ])->save();

            return $locked->fresh();
        });
    }

    public function finishSuccessfully(Consultation $consultation, ?CarbonInterface $finishedAt = null): Consultation
    {
        return $this->finish($consultation, 'completed', true, $finishedAt);
    }

    public function finishWithError(
        Consultation $consultation,
        string $errorCode,
        string $errorMessage,
        string $errorStage,
        string $status = 'failed',
        ?CarbonInterface $finishedAt = null,
    ): Consultation {
        return $this->finish(
            $consultation,
            $status === 'timeout' ? 'timeout' : 'failed',
            false,
            $finishedAt,
            $errorCode,
            $errorMessage,
            $errorStage,
        );
    }

    public function finishCancelled(Consultation $consultation, ?CarbonInterface $finishedAt = null): Consultation
    {
        return $this->finish($consultation, 'cancelled', false, $finishedAt);
    }

    /** @return array{0: int, 1: string} */
    public function classifyProcessingTime(float $seconds): array
    {
        return match (true) {
            $seconds <= 30 => [5, 'Muy rápido'],
            $seconds <= 60 => [4, 'Rápido'],
            $seconds <= 120 => [3, 'Moderado'],
            $seconds <= 180 => [2, 'Lento'],
            default => [1, 'Muy lento'],
        };
    }

    public function calculateElapsedTime(CarbonInterface $startedAt, CarbonInterface $finishedAt): int
    {
        return max(0, $finishedAt->getTimestampMs() - $startedAt->getTimestampMs());
    }

    private function finish(
        Consultation $consultation,
        string $status,
        bool $soapGenerated,
        ?CarbonInterface $finishedAt = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?string $errorStage = null,
    ): Consultation {
        return DB::transaction(function () use ($consultation, $status, $soapGenerated, $finishedAt, $errorCode, $errorMessage, $errorStage): Consultation {
            $locked = Consultation::query()->lockForUpdate()->findOrFail($consultation->id);
            $finish = $finishedAt ?? now();
            $start = $locked->processing_started_at ?? $finish;
            $milliseconds = $this->calculateElapsedTime($start, $finish);
            $seconds = $milliseconds / 1000;
            [$range, $label] = $this->classifyProcessingTime($seconds);

            $locked->forceFill([
                'processing_started_at' => $start,
                'processing_finished_at' => $finish,
                'processing_time_ms' => $milliseconds,
                'processing_time_seconds' => number_format($seconds, 3, '.', ''),
                'processing_time_range' => $range,
                'processing_time_label' => $label,
                'processing_status' => $status,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'error_stage' => $errorStage,
                'retry_count' => max(0, (int) $locked->last_processing_attempt - 1),
                'soap_generated' => $soapGenerated,
            ])->save();

            return $locked->fresh();
        });
    }
}
