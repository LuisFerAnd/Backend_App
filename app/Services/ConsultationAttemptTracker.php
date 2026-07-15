<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationProcessingAttempt;

class ConsultationAttemptTracker
{
    public function current(Consultation $consultation): ConsultationProcessingAttempt
    {
        $attemptNumber = (int) ($consultation->last_processing_attempt ?? 1);

        return ConsultationProcessingAttempt::firstOrCreate(
            ['consultation_id' => $consultation->id, 'attempt_number' => $attemptNumber],
            ['started_at' => now(), 'result' => 'pending']
        );
    }

    public function retry(Consultation $consultation): ConsultationProcessingAttempt
    {
        $number = $consultation->processingAttempts()->max('attempt_number') + 1;
        $consultation->update([
            'last_processing_attempt' => $number,
            'overall_status' => 'transcribing',
            'failure_stage' => null,
            'failure_code' => null,
            'failure_message' => null,
            'user_friendly_error_message' => null,
            'failure_occurred_at' => null,
        ]);

        $processingAttempt = $consultation->processingAttempts()->create([
            'attempt_number' => $number,
            'started_at' => now(),
            'result' => 'pending',
            'segments_received' => $consultation->received_segments,
            'segments_transcribed' => $consultation->transcribed_segments,
        ]);
        app(ProcessingTimeService::class)->startProcessing($consultation->fresh(), restart: true);

        return $processingAttempt;
    }

    public function fail(Consultation $consultation, string $stage, string $code, string $technical, string $friendly, string $status = 'failed'): void
    {
        if ($status === 'failed' && str_contains(strtolower($code.' '.$technical), 'timeout')) {
            $status = 'timeout';
        }
        $consultation->update([
            'overall_status' => $status,
            'failure_stage' => $stage,
            'failure_code' => $code,
            'failure_message' => $technical,
            'user_friendly_error_message' => $friendly,
            'failure_occurred_at' => now(),
            'is_evaluable' => true,
        ]);
        $this->current($consultation)->update([
            'finished_at' => now(),
            // Preserve the historical attempt result vocabulary; the consultation
            // itself carries the more specific `timeout` processing status.
            'result' => 'failed',
            'failure_stage' => $stage,
            'failure_code' => $code,
            'failure_message' => $technical,
            'segments_received' => $consultation->received_segments,
            'segments_transcribed' => $consultation->transcribed_segments,
            'soap_generated' => $consultation->soap_status === 'completed',
            'pdf_generated' => $consultation->pdf_status === 'completed',
        ]);
        app(ProcessingTimeService::class)->finishWithError($consultation->fresh(), $code, $technical, $stage, $status);
    }

    public function cancel(Consultation $consultation): void
    {
        $consultation->update([
            'overall_status' => 'cancelled',
            'finished_at' => $consultation->finished_at ?? now(),
            'is_evaluable' => true,
        ]);
        $this->current($consultation)->update([
            'finished_at' => now(),
            'result' => 'cancelled',
            'segments_received' => $consultation->received_segments,
            'segments_transcribed' => $consultation->transcribed_segments,
            'soap_generated' => false,
            'pdf_generated' => $consultation->pdf_status === 'completed',
        ]);
        app(ProcessingTimeService::class)->finishCancelled($consultation->fresh());
    }

    public function complete(Consultation $consultation): void
    {
        $consultation->update(['overall_status' => 'completed', 'finished_at' => now(), 'is_evaluable' => true]);
        $this->current($consultation)->update([
            'finished_at' => now(),
            'result' => 'completed',
            'segments_received' => $consultation->received_segments,
            'segments_transcribed' => $consultation->transcribed_segments,
            'soap_generated' => true,
            'pdf_generated' => $consultation->pdf_status === 'completed',
        ]);
        $timedConsultation = app(ProcessingTimeService::class)->finishSuccessfully($consultation->fresh());
        if ($timedConsultation->processing_time_seconds !== null) {
            $timedConsultation->soapEvaluation()->update([
                'ai_time_seconds' => (int) round((float) $timedConsultation->processing_time_seconds),
            ]);
        }
    }
}
