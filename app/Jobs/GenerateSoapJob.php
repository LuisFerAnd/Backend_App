<?php

namespace App\Jobs;

use App\Models\Consultation;
use App\Services\ConsultationAttemptTracker;
use App\Services\OpenAIClinicalAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateSoapJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $consultationId) {}

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(OpenAIClinicalAssistant $assistant): void
    {
        $consultation = Consultation::with('patient')->find($this->consultationId);
        if (! $consultation || $consultation->soap_status === 'completed' || $consultation->processing_status === 'cancelled') {
            return;
        }

        $consultation->update([
            'processing_status' => 'generating_soap',
            'overall_status' => 'generating_soap',
            'transcription_status' => 'completed',
            'soap_status' => 'processing',
            'soap_error' => null,
        ]);
        app(ConsultationAttemptTracker::class)->complete($consultation->fresh());
        $result = $assistant->draftConsultation(
            (string) $consultation->transcription_text,
            $consultation->patient
        );
        $draft = $result['draft'];
        $usage = is_array($consultation->vital_signs) ? $consultation->vital_signs : [];
        $usage['ai_usage'] = [
            'soap_model' => $result['model'],
            'transcription_model' => config('services.openai.transcription_model'),
            'transcription_seconds' => $consultation->audioSegments()->sum('duration_seconds'),
            ...(is_array($result['usage'] ?? null) ? $result['usage'] : []),
        ];

        $consultation->update([
            'reason' => $draft['reason'],
            'subjective' => $draft['subjective'],
            'objective' => $draft['objective'],
            'assessment' => $draft['assessment'],
            'plan' => $draft['plan'],
            'vital_signs' => [
                ...$usage,
                ...(is_array($draft['vital_signs'] ?? null) ? $draft['vital_signs'] : []),
            ],
            'processing_status' => 'completed',
            'soap_status' => 'completed',
            'soap_error' => null,
        ]);

        Log::info('Segmented consultation SOAP completed.', [
            'consultation_id' => $consultation->id,
            'session_uuid' => $consultation->session_uuid,
            'received_segments' => $consultation->received_segments,
            'transcribed_segments' => $consultation->transcribed_segments,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $consultation = Consultation::query()->find($this->consultationId);
        if (! $consultation) {
            return;
        }
        $consultation->update([
            'processing_status' => 'failed',
            'soap_status' => 'failed',
            'soap_error' => $exception::class,
        ]);
        app(ConsultationAttemptTracker::class)->fail(
            $consultation,
            'soap_generation',
            class_basename($exception),
            $exception::class,
            'No se pudo generar el registro SOAP. La consulta quedó registrada y puede evaluarse o procesarse nuevamente.'
        );
        Log::error('Segmented consultation SOAP failed.', [
            'consultation_id' => $this->consultationId,
            'error' => $exception::class,
        ]);
    }
}
