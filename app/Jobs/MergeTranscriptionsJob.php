<?php

namespace App\Jobs;

use App\Models\Consultation;
use App\Services\ConsultationAttemptTracker;
use App\Services\TranscriptMerger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class MergeTranscriptionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $consultationId) {}

    public function handle(TranscriptMerger $merger): void
    {
        $consultation = Consultation::query()->find($this->consultationId);
        if (! $consultation || in_array($consultation->processing_status, ['completed', 'cancelled'], true)) {
            return;
        }

        $segments = $consultation->audioSegments()
            ->where('transcription_status', 'completed')
            ->orderBy('segment_number')
            ->get();
        if ($segments->count() !== (int) $consultation->expected_segments) {
            $consultation->update(['processing_status' => 'transcribing']);

            return;
        }

        $transcript = $merger->merge($segments->pluck('transcription_text')->all());
        if ($transcript === '') {
            throw new RuntimeException('Consolidated transcription is empty.');
        }

        $consultation->update([
            'transcription_text' => $transcript,
            'processing_status' => 'generating_soap',
            'transcription_status' => 'completed',
            'overall_status' => 'generating_soap',
            'soap_status' => 'processing',
            'soap_error' => null,
        ]);
        GenerateSoapJob::dispatch($consultation->id);
    }

    public function failed(Throwable $exception): void
    {
        $consultation = Consultation::query()->find($this->consultationId);
        if (! $consultation) {
            return;
        }
        $consultation->update(['processing_status' => 'failed', 'transcription_status' => 'failed']);
        app(ConsultationAttemptTracker::class)->fail(
            $consultation,
            'transcription_merge',
            class_basename($exception),
            $exception::class,
            'No se pudieron consolidar las transcripciones. La consulta quedó registrada y puede reintentarse.'
        );
    }
}
