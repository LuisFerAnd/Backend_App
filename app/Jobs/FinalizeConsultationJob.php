<?php

namespace App\Jobs;

use App\Models\Consultation;
use App\Models\ConsultationAudioSegment;
use App\Services\ConsultationAttemptTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinalizeConsultationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $consultationId) {}

    public function handle(): void
    {
        $consultation = Consultation::query()->find($this->consultationId);
        if (! $consultation || ! $consultation->recording_finished_at) {
            return;
        }
        if (in_array($consultation->processing_status, ['merging_transcriptions', 'generating_soap', 'completed', 'cancelled'], true)) {
            return;
        }

        $expected = (int) $consultation->expected_segments;
        $numbers = $consultation->audioSegments()
            ->orderBy('segment_number')
            ->pluck('segment_number')
            ->map(static fn ($value): int => (int) $value)
            ->all();
        $required = $expected > 0 ? range(1, $expected) : [];

        $audioDuration = (int) $consultation->audioSegments()->sum('duration_seconds');
        $consultation->update([
            'received_segments' => count($numbers),
            'transcribed_segments' => $consultation->audioSegments()
                ->where('transcription_status', 'completed')
                ->count(),
            'recording_duration_seconds' => $audioDuration,
        ]);
        $consultation->soapEvaluation()->update(['audio_duration_seconds' => $audioDuration]);
        $consultation->refresh();

        if ($required === [] || $numbers !== $required) {
            $consultation->update(['processing_status' => 'waiting_segments', 'upload_status' => 'uploading']);

            return;
        }
        if ($consultation->audioSegments()->where('transcription_status', 'failed')->exists()) {
            $consultation->update(['processing_status' => 'failed', 'transcription_status' => 'failed', 'overall_status' => 'failed']);
            app(ConsultationAttemptTracker::class)->fail(
                $consultation->fresh(),
                'transcription',
                'TRANSCRIPTION_SEGMENT_FAILED',
                'Uno o más segmentos no pudieron transcribirse.',
                'La consulta fue registrada, pero el SOAP no pudo generarse.'
            );

            return;
        }

        $strategy = $consultation->transcription_strategy;
        if (! in_array($strategy, ['single', 'segmented'], true)) {
            $totalBytes = (int) $consultation->audioSegments()->sum('file_size');
            $maxBytes = max(1, (int) config('services.openai.single_transcription_max_kb', 24576)) * 1024;
            $segmentProcessingStarted = $consultation->audioSegments()
                ->where('transcription_status', '!=', 'pending')
                ->exists();
            $strategy = ! $segmentProcessingStarted && $totalBytes <= $maxBytes
                ? 'single'
                : 'segmented';
            Consultation::query()
                ->whereKey($consultation->id)
                ->whereNull('transcription_strategy')
                ->update(['transcription_strategy' => $strategy]);
            $consultation->refresh();
            $strategy = $consultation->transcription_strategy ?? $strategy;
        }

        if ($strategy === 'single') {
            if ($consultation->transcription_status === 'completed') {
                if ($consultation->soap_status !== 'completed' && filled($consultation->transcription_text)) {
                    GenerateSoapJob::dispatch($consultation->id);
                }

                return;
            }
            if (! in_array($consultation->transcription_status, ['queued', 'processing', 'completed'], true)) {
                $updated = Consultation::query()
                    ->whereKey($consultation->id)
                    ->whereNotIn('transcription_status', ['queued', 'processing', 'completed'])
                    ->update([
                        'processing_status' => 'transcribing',
                        'upload_status' => 'completed',
                        'transcription_status' => 'queued',
                        'overall_status' => 'transcribing',
                    ]);
                if ($updated === 1) {
                    TranscribeConsultationAudioJob::dispatch($consultation->id);
                }
            }

            return;
        }

        if ($consultation->transcribed_segments !== $expected) {
            $consultation->update(['processing_status' => 'transcribing', 'upload_status' => 'completed', 'transcription_status' => 'processing', 'overall_status' => 'transcribing']);
            $consultation->audioSegments()
                ->where('transcription_status', 'pending')
                ->pluck('id')
                ->each(function ($segmentId): void {
                    $queued = ConsultationAudioSegment::query()
                        ->whereKey($segmentId)
                        ->where('transcription_status', 'pending')
                        ->update(['transcription_status' => 'queued']);
                    if ($queued === 1) {
                        TranscribeAudioSegmentJob::dispatch((int) $segmentId);
                    }
                });

            return;
        }

        $updated = Consultation::query()
            ->whereKey($consultation->id)
            ->whereNotIn('processing_status', ['merging_transcriptions', 'generating_soap', 'completed'])
            ->update(['processing_status' => 'merging_transcriptions']);
        if ($updated === 1) {
            MergeTranscriptionsJob::dispatch($consultation->id);
        }
    }
}
