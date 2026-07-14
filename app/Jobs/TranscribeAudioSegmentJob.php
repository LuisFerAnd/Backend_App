<?php

namespace App\Jobs;

use App\Models\ConsultationAudioSegment;
use App\Services\ConsultationAttemptTracker;
use App\Services\OpenAIClinicalAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TranscribeAudioSegmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public function __construct(public int $segmentId) {}

    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(OpenAIClinicalAssistant $assistant): void
    {
        $segment = ConsultationAudioSegment::query()->find($this->segmentId);
        if (! $segment || $segment->transcription_status === 'completed' || $segment->consultation?->processing_status === 'cancelled') {
            return;
        }

        if (! Storage::disk('local')->exists($segment->storage_path)) {
            throw new RuntimeException('Stored audio segment is missing.');
        }

        $segment->update([
            'transcription_status' => 'processing',
            'retry_count' => max(0, $this->attempts() - 1),
            'error_message' => null,
        ]);

        $path = Storage::disk('local')->path($segment->storage_path);
        $audio = new UploadedFile(
            $path,
            $segment->original_filename,
            mime_content_type($path) ?: 'application/octet-stream',
            null,
            true
        );
        $result = $assistant->transcribe($audio);

        $segment->update([
            'transcription_status' => 'completed',
            'transcription_text' => $result['text'],
            'error_message' => null,
        ]);

        $consultation = $segment->consultation;
        $consultation->update([
            'transcribed_segments' => $consultation->audioSegments()
                ->where('transcription_status', 'completed')
                ->count(),
            'processing_status' => $consultation->recording_finished_at
                ? 'transcribing'
                : 'recording',
            'transcription_status' => 'processing',
            'overall_status' => $consultation->recording_finished_at ? 'transcribing' : 'recording',
        ]);

        Log::info('Audio segment transcribed.', $this->logContext($segment));

        if ($consultation->recording_finished_at) {
            FinalizeConsultationJob::dispatch($consultation->id);
        }
    }

    public function failed(Throwable $exception): void
    {
        $segment = ConsultationAudioSegment::query()->find($this->segmentId);
        if (! $segment) {
            return;
        }
        $segment->update([
            'transcription_status' => 'failed',
            'retry_count' => $this->tries,
            'error_message' => $exception::class,
        ]);
        $consultation = $segment->consultation;
        $consultation->update(['processing_status' => 'failed', 'transcription_status' => 'failed']);
        app(ConsultationAttemptTracker::class)->fail(
            $consultation,
            'transcription',
            class_basename($exception),
            $exception::class,
            'No se pudo completar la transcripción. La consulta quedó registrada y puede evaluarse o procesarse nuevamente.'
        );
        Log::error('Audio segment transcription failed.', [
            ...$this->logContext($segment),
            'error' => $exception::class,
        ]);
    }

    private function logContext(ConsultationAudioSegment $segment): array
    {
        return [
            'consultation_id' => $segment->consultation_id,
            'session_uuid' => $segment->session_uuid,
            'segment_number' => $segment->segment_number,
            'file_size' => $segment->file_size,
            'checksum' => $segment->checksum,
            'upload_status' => $segment->upload_status,
            'transcription_status' => $segment->transcription_status,
        ];
    }
}
