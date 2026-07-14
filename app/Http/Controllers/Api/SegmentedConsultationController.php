<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\FinalizeConsultationJob;
use App\Jobs\GenerateSoapJob;
use App\Jobs\TranscribeAudioSegmentJob;
use App\Models\Consultation;
use App\Models\ConsultationAudioSegment;
use App\Models\Patient;
use App\Services\ConsultationAttemptTracker;
use App\Services\SoapEvaluationFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SegmentedConsultationController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'session_uuid' => ['required', 'uuid'],
            'started_at' => ['sometimes', 'date'],
            'local_consultation_code' => ['sometimes', 'string', 'max:50'],
            'created_offline' => ['sometimes', 'boolean'],
        ]);
        $patient = $this->findDoctorPatient($request, (int) $data['patient_id']);

        $consultation = Consultation::query()
            ->where('doctor_id', $request->user()->id)
            ->where('session_uuid', $data['session_uuid'])
            ->first();
        $created = false;

        if (! $consultation) {
            try {
                $consultation = Consultation::create([
                    'doctor_id' => $request->user()->id,
                    'patient_id' => $patient->id,
                    'session_uuid' => $data['session_uuid'],
                    'consulted_at' => $data['started_at'] ?? now(),
                    'started_at' => $data['started_at'] ?? now(),
                    'local_consultation_code' => $data['local_consultation_code'] ?? null,
                    'created_offline' => (bool) ($data['created_offline'] ?? false),
                    'synced_at' => now(),
                    'reason' => 'no especificado',
                    'subjective' => 'no especificado',
                    'objective' => 'no especificado',
                    'assessment' => 'no especificado',
                    'plan' => 'no especificado',
                    'recording_status' => 'recording',
                    'upload_status' => 'not_started',
                    'transcription_status' => 'not_started',
                    'processing_status' => 'recording',
                    'soap_status' => 'pending',
                    'pdf_status' => 'not_generated',
                    'overall_status' => 'recording',
                    'is_evaluable' => true,
                ]);
                $created = true;
            } catch (QueryException) {
                $consultation = Consultation::query()
                    ->where('doctor_id', $request->user()->id)
                    ->where('session_uuid', $data['session_uuid'])
                    ->firstOrFail();
            }
        } elseif ($consultation->patient_id !== $patient->id) {
            return response()->json([
                'success' => false,
                'message' => 'La sesión ya está asociada con otra consulta.',
            ], 409);
        }
        if ($created) {
            $consultation->update([
                'consultation_code' => 'C-'.$consultation->started_at->timezone(config('app.timezone'))->format('d-m-Y').'-'.str_pad((string) $consultation->id, 6, '0', STR_PAD_LEFT),
            ]);
            app(ConsultationAttemptTracker::class)->current($consultation);
            app(SoapEvaluationFactory::class)->firstOrCreate($consultation, $request->user());
        }

        return response()->json([
            'success' => true,
            'consultation_id' => $consultation->id,
            'session_uuid' => $consultation->session_uuid,
            'consultation_code' => $consultation->consultation_code,
            'local_consultation_code' => $consultation->local_consultation_code,
            'status' => $consultation->recording_status,
        ], $created ? 201 : 200);
    }

    public function uploadSegment(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorizeConsultation($request, $consultation);
        if ($consultation->processing_status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'El envío de esta consulta fue cancelado.'], 409);
        }
        $maxKilobytes = (int) config('services.openai.segment_max_kb', 20480);
        $data = $request->validate([
            'audio' => ['required', 'file', 'mimes:m4a,mp4,aac,wav,mp3,ogg,webm', "max:$maxKilobytes"],
            'session_uuid' => ['required', 'uuid'],
            'segment_number' => ['required', 'integer', 'min:1'],
            'duration_seconds' => ['required', 'integer', 'min:0', 'max:600'],
            'is_final' => ['required', 'boolean'],
            'checksum' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        if ($consultation->session_uuid !== $data['session_uuid']) {
            return response()->json(['success' => false, 'message' => 'La sesión no corresponde a la consulta.'], 409);
        }
        if ($consultation->expected_segments !== null &&
            (int) $data['segment_number'] > $consultation->expected_segments) {
            throw ValidationException::withMessages([
                'segment_number' => 'El número supera la cantidad de segmentos finalizada.',
            ]);
        }

        $audio = $request->file('audio');
        if (! $audio || (int) $audio->getSize() <= 0) {
            throw ValidationException::withMessages(['audio' => 'El archivo de audio está vacío.']);
        }
        $allowedMimes = [
            'audio/mp4', 'audio/x-m4a', 'video/mp4', 'audio/aac', 'audio/x-aac',
            'audio/wav', 'audio/x-wav', 'audio/mpeg', 'audio/ogg', 'video/webm', 'audio/webm',
        ];
        if (! in_array((string) $audio->getMimeType(), $allowedMimes, true)) {
            throw ValidationException::withMessages(['audio' => 'El tipo real del archivo de audio no está permitido.']);
        }

        $actualChecksum = hash_file('sha256', $audio->getRealPath());
        if (! hash_equals(strtolower($data['checksum']), strtolower($actualChecksum))) {
            throw ValidationException::withMessages(['checksum' => 'El checksum del archivo no coincide.']);
        }

        $existing = ConsultationAudioSegment::query()
            ->where('session_uuid', $data['session_uuid'])
            ->where('segment_number', $data['segment_number'])
            ->first();
        if ($existing) {
            if (! hash_equals($existing->checksum, strtolower($actualChecksum))) {
                return response()->json([
                    'success' => false,
                    'message' => 'El número de segmento ya existe con otro contenido.',
                ], 409);
            }

            return $this->segmentResponse($existing, duplicate: true);
        }

        $extension = strtolower($audio->guessExtension() ?: $audio->getClientOriginalExtension() ?: 'm4a');
        $filename = sprintf(
            'segment_%03d_%s.%s',
            (int) $data['segment_number'],
            substr($actualChecksum, 0, 12),
            $extension
        );
        $directory = 'consultations/'.$consultation->session_uuid.'/segments';
        $path = $audio->storeAs($directory, $filename, 'local');
        if (! $path) {
            return response()->json(['success' => false, 'message' => 'No se pudo guardar el fragmento de audio.'], 500);
        }

        try {
            $segment = DB::transaction(function () use ($consultation, $data, $audio, $path, $actualChecksum): ConsultationAudioSegment {
                $segment = ConsultationAudioSegment::create([
                    'consultation_id' => $consultation->id,
                    'session_uuid' => $consultation->session_uuid,
                    'segment_number' => $data['segment_number'],
                    'original_filename' => $audio->getClientOriginalName(),
                    'storage_path' => $path,
                    'duration_seconds' => $data['duration_seconds'],
                    'file_size' => $audio->getSize(),
                    'checksum' => strtolower($actualChecksum),
                    'upload_status' => 'uploaded',
                    'transcription_status' => 'pending',
                    'is_final' => $data['is_final'],
                ]);
                $consultation->update([
                    'received_segments' => $consultation->audioSegments()->count(),
                    'upload_status' => 'uploading',
                    'processing_status' => $consultation->recording_finished_at ? 'transcribing' : 'recording',
                    'overall_status' => $consultation->recording_finished_at ? 'transcribing' : 'recording',
                ]);

                return $segment;
            });
        } catch (QueryException $exception) {
            $segment = ConsultationAudioSegment::query()
                ->where('session_uuid', $data['session_uuid'])
                ->where('segment_number', $data['segment_number'])
                ->firstOrFail();
            if (! hash_equals($segment->checksum, strtolower($actualChecksum))) {
                Storage::disk('local')->delete($path);

                return response()->json(['success' => false, 'message' => 'Conflicto de checksum.'], 409);
            }

            return $this->segmentResponse($segment, duplicate: true);
        }

        Log::info('Audio segment uploaded.', $this->logContext($segment));
        if ($consultation->recording_finished_at) {
            FinalizeConsultationJob::dispatch($consultation->id);
        }

        return $this->segmentResponse($segment, duplicate: false);
    }

    public function finalize(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorizeConsultation($request, $consultation);
        if ($consultation->processing_status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'El procesamiento fue cancelado.'], 409);
        }
        $data = $request->validate([
            'session_uuid' => ['required', 'uuid'],
            'expected_segments' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        if ($consultation->session_uuid !== $data['session_uuid']) {
            return response()->json(['success' => false, 'message' => 'La sesión no corresponde a la consulta.'], 409);
        }
        if ($consultation->expected_segments !== null &&
            (int) $consultation->expected_segments !== (int) $data['expected_segments']) {
            return response()->json([
                'success' => false,
                'message' => 'La cantidad final de segmentos no puede cambiar.',
            ], 409);
        }
        if ($consultation->processing_status === 'completed') {
            return response()->json([
                'success' => true,
                'status' => 'completed',
                'message' => 'La consulta ya fue procesada',
            ]);
        }
        if (in_array($consultation->processing_status, ['merging_transcriptions', 'generating_soap'], true)) {
            return response()->json([
                'success' => true,
                'status' => 'processing',
                'message' => 'La consulta ya está siendo procesada',
            ], 202);
        }

        $transcriptionStatus = in_array($consultation->transcription_status, ['queued', 'processing', 'completed'], true)
            ? $consultation->transcription_status
            : 'pending';
        $processingStatus = in_array($transcriptionStatus, ['queued', 'processing'], true)
            ? 'transcribing'
            : 'waiting_segments';
        $consultation->update([
            'recording_status' => 'finished',
            'upload_status' => $consultation->received_segments >= $data['expected_segments'] ? 'completed' : 'uploading',
            'transcription_status' => $transcriptionStatus,
            'processing_status' => $processingStatus,
            'overall_status' => 'transcribing',
            'expected_segments' => $data['expected_segments'],
            'recording_finished_at' => $consultation->recording_finished_at ?? now(),
            'finished_at' => now(),
            'recording_duration_seconds' => $consultation->audioSegments()->sum('duration_seconds'),
            'soap_status' => $consultation->soap_status === 'completed' ? 'completed' : 'pending',
        ]);
        FinalizeConsultationJob::dispatch($consultation->id);

        return response()->json([
            'success' => true,
            'status' => 'processing',
            'message' => 'La consulta está siendo procesada',
        ], 202);
    }

    public function status(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorizeConsultation($request, $consultation);
        $consultation->refresh();
        $failed = $consultation->audioSegments()->where('transcription_status', 'failed')->count();
        $pending = max(0, (int) ($consultation->expected_segments ?? $consultation->received_segments) - $consultation->transcribed_segments);

        return response()->json([
            'success' => true,
            'consultation_id' => $consultation->id,
            'consultation_code' => $consultation->consultation_code,
            'session_uuid' => $consultation->session_uuid,
            'processing_status' => $consultation->processing_status,
            'expected_segments' => $consultation->expected_segments,
            'received_segments' => $consultation->received_segments,
            'transcribed_segments' => $consultation->transcribed_segments,
            'pending_segments' => $pending,
            'failed_segments' => $failed,
            'soap_status' => $consultation->soap_status,
            'recording_status' => $consultation->recording_status,
            'upload_status' => $consultation->upload_status,
            'transcription_status' => $consultation->transcription_status,
            'transcription_strategy' => $consultation->transcription_strategy,
            'pdf_status' => $consultation->pdf_status,
            'overall_status' => $consultation->overall_status,
            'failure_stage' => $consultation->failure_stage,
            'failure_code' => $consultation->failure_code,
            'failure_message' => $consultation->user_friendly_error_message,
            'is_evaluable' => $consultation->is_evaluable,
            'progress_percentage' => $this->progress($consultation),
            'message' => $consultation->processing_status === 'failed'
                ? ($consultation->user_friendly_error_message ?? 'El procesamiento falló. La consulta y sus fragmentos fueron conservados.')
                : $this->statusMessage($consultation->processing_status),
            'soap' => $consultation->processing_status === 'completed' ? [
                'reason' => $consultation->reason,
                'subjective' => $consultation->subjective,
                'objective' => $consultation->objective,
                'assessment' => $consultation->assessment,
                'plan' => $consultation->plan,
                'vital_signs' => $consultation->vital_signs,
            ] : null,
        ]);
    }

    public function missingSegments(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorizeConsultation($request, $consultation);
        $received = $consultation->audioSegments()->orderBy('segment_number')->pluck('segment_number')->map(fn ($value) => (int) $value)->all();
        $expected = (int) ($consultation->expected_segments ?? 0);

        return response()->json([
            'expected_segments' => $expected,
            'received_segments' => $received,
            'missing_segments' => $expected > 0 ? array_values(array_diff(range(1, $expected), $received)) : [],
        ]);
    }

    public function retryTranscription(Request $request, Consultation $consultation, ConsultationAudioSegment $segment): JsonResponse
    {
        $this->authorizeConsultation($request, $consultation);
        abort_if($segment->consultation_id !== $consultation->id, 404);
        if ($segment->transcription_status !== 'completed') {
            $segment->update(['transcription_status' => 'queued', 'error_message' => null]);
            $consultation->update([
                'transcription_strategy' => 'segmented',
                'processing_status' => 'transcribing',
                'transcription_status' => 'processing',
                'overall_status' => 'transcribing',
            ]);
            TranscribeAudioSegmentJob::dispatch($segment->id);
        }

        return response()->json(['success' => true, 'status' => $segment->fresh()->transcription_status]);
    }

    public function retryProcessing(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorizeConsultation($request, $consultation);
        app(ConsultationAttemptTracker::class)->retry($consultation);
        if ($consultation->soap_status === 'failed' && filled($consultation->transcription_text)) {
            $consultation->update([
                'processing_status' => 'generating_soap',
                'soap_status' => 'pending',
                'soap_error' => null,
            ]);
            GenerateSoapJob::dispatch($consultation->id);
        } else {
            $consultation->audioSegments()
                ->where('transcription_status', 'failed')
                ->each(function (ConsultationAudioSegment $segment): void {
                    $segment->update(['transcription_status' => 'pending', 'error_message' => null]);
                });
            $consultation->update([
                'processing_status' => 'transcribing',
                'transcription_status' => 'pending',
                'overall_status' => 'transcribing',
            ]);
            FinalizeConsultationJob::dispatch($consultation->id);
        }

        return response()->json([
            'success' => true,
            'status' => 'processing',
            'message' => 'El procesamiento se volverá a intentar',
        ], 202);
    }

    public function cancelProcessing(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorizeConsultation($request, $consultation);
        if ($consultation->processing_status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'La consulta ya fue procesada y no puede cancelarse.',
            ], 409);
        }

        $consultation->update([
            'processing_status' => 'cancelled',
            'overall_status' => 'cancelled',
            'recording_status' => 'finished',
            'finished_at' => $consultation->finished_at ?? now(),
        ]);

        return response()->json([
            'success' => true,
            'status' => 'cancelled',
            'message' => 'El procesamiento fue cancelado.',
        ]);
    }

    public function reportFailure(Request $request, Consultation $consultation, ConsultationAttemptTracker $tracker): JsonResponse
    {
        $this->authorizeConsultation($request, $consultation);
        $data = $request->validate([
            'failure_stage' => ['required', 'string', 'in:consultation_creation,recording,segment_creation,local_storage,segment_upload,audio_upload,transcription,transcription_merge,soap_generation,pdf_generation,network,authentication,server,unknown'],
            'failure_code' => ['required', 'string', 'max:100'],
            'failure_message' => ['required', 'string', 'max:2000'],
        ]);
        $tracker->fail(
            $consultation,
            $data['failure_stage'],
            $data['failure_code'],
            $data['failure_message'],
            'No se pudo completar esta etapa. La consulta quedó registrada y puede evaluarse o reintentarse.'
        );

        return response()->json(['success' => true, 'consultation_code' => $consultation->consultation_code]);
    }

    private function segmentResponse(ConsultationAudioSegment $segment, bool $duplicate): JsonResponse
    {
        return response()->json([
            'success' => true,
            'segment_id' => $segment->id,
            'segment_number' => $segment->segment_number,
            'status' => 'uploaded',
            'checksum' => $segment->checksum,
            'duplicate' => $duplicate,
        ], $duplicate ? 200 : 201);
    }

    private function findDoctorPatient(Request $request, int $patientId): Patient
    {
        return Patient::query()->where('doctor_id', $request->user()->id)->findOrFail($patientId);
    }

    private function authorizeConsultation(Request $request, Consultation $consultation): void
    {
        abort_if($consultation->doctor_id !== $request->user()->id, 404);
    }

    private function progress(Consultation $consultation): int
    {
        if ($consultation->processing_status === 'completed') {
            return 100;
        }
        if ($consultation->processing_status === 'failed') {
            return 0;
        }
        if ($consultation->processing_status === 'generating_soap') {
            return 95;
        }
        if ($consultation->processing_status === 'merging_transcriptions') {
            return 85;
        }
        $expected = max(1, (int) ($consultation->expected_segments ?? $consultation->received_segments ?? 1));
        $receivedProgress = min(40, (int) floor(($consultation->received_segments / $expected) * 40));
        $transcribedProgress = min(40, (int) floor(($consultation->transcribed_segments / $expected) * 40));

        return $receivedProgress + $transcribedProgress;
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'recording' => 'Grabando consulta',
            'uploading' => 'Enviando segmentos',
            'waiting_segments' => 'Esperando segmentos pendientes',
            'transcribing' => 'Transcribiendo audio',
            'merging_transcriptions' => 'Consolidando transcripción',
            'generating_soap' => 'Generando registro SOAP',
            'completed' => 'Registro SOAP generado correctamente',
            'failed' => 'No se pudo completar el procesamiento',
            'cancelled' => 'Procesamiento cancelado',
            default => 'Procesando consulta',
        };
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
