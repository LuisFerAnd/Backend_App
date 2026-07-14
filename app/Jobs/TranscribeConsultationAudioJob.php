<?php

namespace App\Jobs;

use App\Models\Consultation;
use App\Services\AudioSegmentConsolidator;
use App\Services\ConsultationAttemptTracker;
use App\Services\OpenAIClinicalAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TranscribeConsultationAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 200;

    public function __construct(public int $consultationId) {}

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(AudioSegmentConsolidator $consolidator, OpenAIClinicalAssistant $assistant): void
    {
        $consultation = Consultation::query()->find($this->consultationId);
        if (! $consultation) {
            return;
        }
        if ($consultation->transcription_status === 'completed') {
            if ($consultation->soap_status !== 'completed' && filled($consultation->transcription_text)) {
                GenerateSoapJob::dispatch($consultation->id);
            }

            return;
        }
        if ($consultation->transcription_strategy !== 'single') {
            return;
        }

        $consultation->update([
            'processing_status' => 'transcribing',
            'transcription_status' => 'processing',
            'overall_status' => 'transcribing',
        ]);

        try {
            $storagePath = $consolidator->consolidate($consultation);
        } catch (Throwable $exception) {
            Log::warning('Single audio consolidation unavailable; switching to segmented transcription.', [
                'consultation_id' => $consultation->id,
                'session_uuid' => $consultation->session_uuid,
                'error' => $exception::class,
            ]);
            $this->fallbackToSegments($consultation);

            return;
        }

        $path = Storage::disk('local')->path($storagePath);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size <= 0) {
            throw new RuntimeException('El audio consolidado no está disponible.');
        }

        $audio = new UploadedFile(
            $path,
            'consultation_'.$consultation->session_uuid.'.m4a',
            mime_content_type($path) ?: 'audio/mp4',
            null,
            true
        );
        $result = $assistant->transcribe($audio);

        DB::transaction(function () use ($consultation, $result, $storagePath, $size): void {
            $consultation->audioSegments()->update([
                'transcription_status' => 'completed',
                'error_message' => null,
            ]);
            $consultation->update([
                'transcription_text' => $result['text'],
                'transcribed_segments' => (int) $consultation->expected_segments,
                'consolidated_audio_path' => $storagePath,
                'consolidated_audio_size' => $size,
                'processing_status' => 'generating_soap',
                'transcription_status' => 'completed',
                'overall_status' => 'generating_soap',
                'soap_status' => 'processing',
                'soap_error' => null,
            ]);
        });

        Log::info('Consultation audio transcribed as a single file.', [
            'consultation_id' => $consultation->id,
            'session_uuid' => $consultation->session_uuid,
            'audio_size' => $size,
            'segments' => $consultation->expected_segments,
        ]);
        GenerateSoapJob::dispatch($consultation->id);
    }

    public function failed(Throwable $exception): void
    {
        $consultation = Consultation::query()->find($this->consultationId);
        if (! $consultation || $consultation->transcription_strategy !== 'single') {
            return;
        }
        $consultation->update([
            'processing_status' => 'failed',
            'transcription_status' => 'failed',
            'overall_status' => 'failed',
        ]);
        app(ConsultationAttemptTracker::class)->fail(
            $consultation,
            'transcription',
            class_basename($exception),
            $exception::class,
            'No se pudo completar la transcripción. El audio quedó guardado y puede procesarse nuevamente.'
        );
        Log::error('Single consultation audio transcription failed.', [
            'consultation_id' => $this->consultationId,
            'error' => $exception::class,
        ]);
    }

    private function fallbackToSegments(Consultation $consultation): void
    {
        DB::transaction(function () use ($consultation): void {
            $consultation->audioSegments()
                ->where('transcription_status', '!=', 'completed')
                ->update([
                    'transcription_status' => 'pending',
                    'error_message' => null,
                ]);
            $consultation->update([
                'transcription_strategy' => 'segmented',
                'transcription_status' => 'pending',
                'processing_status' => 'transcribing',
                'overall_status' => 'transcribing',
                'consolidated_audio_path' => null,
                'consolidated_audio_size' => null,
            ]);
        });
        FinalizeConsultationJob::dispatch($consultation->id);
    }
}
