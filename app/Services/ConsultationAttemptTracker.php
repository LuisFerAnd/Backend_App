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

        return $consultation->processingAttempts()->create([
            'attempt_number' => $number,
            'started_at' => now(),
            'result' => 'pending',
            'segments_received' => $consultation->received_segments,
            'segments_transcribed' => $consultation->transcribed_segments,
        ]);
    }

    public function fail(Consultation $consultation, string $stage, string $code, string $technical, string $friendly): void
    {
        $consultation->update([
            'overall_status' => 'failed',
            'failure_stage' => $stage,
            'failure_code' => $code,
            'failure_message' => $technical,
            'user_friendly_error_message' => $friendly,
            'failure_occurred_at' => now(),
            'is_evaluable' => true,
        ]);
        $this->current($consultation)->update([
            'finished_at' => now(),
            'result' => 'failed',
            'failure_stage' => $stage,
            'failure_code' => $code,
            'failure_message' => $technical,
            'segments_received' => $consultation->received_segments,
            'segments_transcribed' => $consultation->transcribed_segments,
            'soap_generated' => $consultation->soap_status === 'completed',
            'pdf_generated' => $consultation->pdf_status === 'completed',
        ]);
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
    }
}
