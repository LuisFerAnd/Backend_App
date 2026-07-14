<?php

namespace Tests\Feature;

use App\Jobs\FinalizeConsultationJob;
use App\Jobs\GenerateSoapJob;
use App\Jobs\MergeTranscriptionsJob;
use App\Jobs\TranscribeAudioSegmentJob;
use App\Jobs\TranscribeConsultationAudioJob;
use App\Models\Consultation;
use App\Models\ConsultationAudioSegment;
use App\Services\AudioSegmentConsolidator;
use App\Services\OpenAIClinicalAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class SegmentedConsultationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $patientId;

    private string $sessionUuid = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->token = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Segmentos',
            'email' => 'segments@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');
        $this->patientId = $this->withToken($this->token)->postJson('/api/patients', [
            'first_name' => 'Paciente',
            'last_name' => 'Prueba',
            'dni' => '0801199019999',
        ])->json('patient.id');
    }

    public function test_uploads_segment_without_transcribing_before_finalize(): void
    {
        $consultation = $this->createSegmentedSession();
        $response = $this->upload($consultation, 1, 'first-audio');

        $response->assertCreated()
            ->assertJsonPath('segment_number', 1)
            ->assertJsonPath('duplicate', false);
        $segment = ConsultationAudioSegment::firstOrFail();
        Storage::disk('local')->assertExists($segment->storage_path);
        Queue::assertNotPushed(TranscribeAudioSegmentJob::class);
        Queue::assertNotPushed(TranscribeConsultationAudioJob::class);
    }

    public function test_same_checksum_is_idempotent(): void
    {
        $consultation = $this->createSegmentedSession();
        $this->upload($consultation, 1, 'same-content')->assertCreated();
        $this->upload($consultation, 1, 'same-content')
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('consultation_audio_segments', 1);
        Queue::assertNotPushed(TranscribeAudioSegmentJob::class);
    }

    public function test_same_number_with_another_checksum_returns_conflict(): void
    {
        $consultation = $this->createSegmentedSession();
        $this->upload($consultation, 1, 'content-a')->assertCreated();
        $this->upload($consultation, 1, 'content-b')->assertConflict();
    }

    public function test_rejects_empty_and_disallowed_files(): void
    {
        $consultation = $this->createSegmentedSession();
        $empty = UploadedFile::fake()->create('empty.m4a', 0, 'audio/mp4');
        $this->postSegment($consultation, $empty, 1)->assertUnprocessable();

        $text = UploadedFile::fake()->create('payload.txt', 2, 'text/plain');
        $this->postSegment($consultation, $text, 2)->assertUnprocessable();
    }

    public function test_rejects_unknown_consultation_and_wrong_session(): void
    {
        $file = UploadedFile::fake()->create('segment.m4a', 2, 'audio/mp4');
        $this->postSegment(999999, $file, 1)->assertNotFound();

        $consultation = $this->createSegmentedSession();
        $wrong = UploadedFile::fake()->create('segment.m4a', 2, 'audio/mp4');
        $checksum = hash_file('sha256', $wrong->getRealPath());
        $this->withToken($this->token)->post("/api/consultations/$consultation/segments", [
            'audio' => $wrong,
            'session_uuid' => 'b4ad02d9-524f-4e5f-958c-37f38b50b558',
            'segment_number' => 1,
            'duration_seconds' => 60,
            'is_final' => false,
            'checksum' => $checksum,
        ])->assertConflict();
    }

    public function test_finalize_reports_processing_with_complete_or_missing_segments(): void
    {
        $consultation = $this->createSegmentedSession();
        $this->upload($consultation, 1, 'one');
        $this->upload($consultation, 2, 'two', true);

        $this->withToken($this->token)->postJson("/api/consultations/$consultation/finalize", [
            'session_uuid' => $this->sessionUuid,
            'expected_segments' => 2,
        ])->assertAccepted()->assertJsonPath('status', 'processing');
        Queue::assertPushed(FinalizeConsultationJob::class);
        (new FinalizeConsultationJob($consultation))->handle();
        $this->assertDatabaseHas('consultations', [
            'id' => $consultation,
            'transcription_strategy' => 'single',
            'transcription_status' => 'queued',
        ]);
        Queue::assertPushed(TranscribeConsultationAudioJob::class);
        $this->withToken($this->token)->postJson("/api/consultations/$consultation/finalize", [
            'session_uuid' => $this->sessionUuid,
            'expected_segments' => 3,
        ])->assertConflict();

        $missingConsultation = $this->createSegmentedSession('790f7a58-1ef2-454d-81cc-607fd608c031');
        $this->upload($missingConsultation, 1, 'one', false, '790f7a58-1ef2-454d-81cc-607fd608c031');
        $this->withToken($this->token)->postJson("/api/consultations/$missingConsultation/finalize", [
            'session_uuid' => '790f7a58-1ef2-454d-81cc-607fd608c031',
            'expected_segments' => 2,
        ])->assertAccepted();
        (new FinalizeConsultationJob($missingConsultation))->handle();
        $this->assertDatabaseHas('consultations', [
            'id' => $missingConsultation,
            'processing_status' => 'waiting_segments',
        ]);
    }

    public function test_status_and_missing_segment_endpoints(): void
    {
        $consultation = $this->createSegmentedSession();
        $this->upload($consultation, 1, 'one');
        $this->upload($consultation, 3, 'three', true);
        Consultation::findOrFail($consultation)->update(['expected_segments' => 3]);

        $this->withToken($this->token)
            ->getJson("/api/consultations/$consultation/processing-status")
            ->assertOk()
            ->assertJsonStructure(['processing_status', 'progress_percentage', 'pending_segments']);
        $this->withToken($this->token)
            ->getJson("/api/consultations/$consultation/missing-segments")
            ->assertOk()
            ->assertJsonPath('missing_segments', [2]);
    }

    public function test_does_not_merge_or_generate_soap_before_all_transcriptions(): void
    {
        $consultationId = $this->createSegmentedSession();
        $this->upload($consultationId, 1, 'one');
        $this->upload($consultationId, 2, 'two', true);
        Consultation::findOrFail($consultationId)->update([
            'recording_finished_at' => now(),
            'expected_segments' => 2,
        ]);
        ConsultationAudioSegment::where('segment_number', 1)->update([
            'transcription_status' => 'completed',
            'transcription_text' => 'Primera parte.',
        ]);

        (new FinalizeConsultationJob($consultationId))->handle();

        $this->assertDatabaseHas('consultations', [
            'id' => $consultationId,
            'processing_status' => 'transcribing',
            'soap_status' => 'pending',
        ]);
        Queue::assertNotPushed(MergeTranscriptionsJob::class);
        Queue::assertNotPushed(GenerateSoapJob::class);
    }

    public function test_failed_transcription_can_be_requeued(): void
    {
        $consultation = $this->createSegmentedSession();
        $this->upload($consultation, 1, 'one');
        $segment = ConsultationAudioSegment::firstOrFail();
        $segment->update(['transcription_status' => 'failed']);

        $this->withToken($this->token)
            ->postJson("/api/consultations/$consultation/segments/$segment->id/retry-transcription")
            ->assertOk()
            ->assertJsonPath('status', 'queued');
        Queue::assertPushed(TranscribeAudioSegmentJob::class);
    }

    public function test_large_audio_uses_segmented_transcription_fallback(): void
    {
        config(['services.openai.single_transcription_max_kb' => 1]);
        $consultationId = $this->createSegmentedSession();
        $this->upload($consultationId, 1, 'one');
        $this->upload($consultationId, 2, 'two', true);
        ConsultationAudioSegment::query()->update(['file_size' => 2048]);
        Consultation::findOrFail($consultationId)->update([
            'recording_finished_at' => now(),
            'expected_segments' => 2,
            'transcription_status' => 'pending',
        ]);

        (new FinalizeConsultationJob($consultationId))->handle();

        $this->assertDatabaseHas('consultations', [
            'id' => $consultationId,
            'transcription_strategy' => 'segmented',
        ]);
        Queue::assertPushedTimes(TranscribeAudioSegmentJob::class, 2);
        Queue::assertNotPushed(TranscribeConsultationAudioJob::class);
    }

    public function test_single_audio_transcription_generates_one_transcript_and_soap_job(): void
    {
        $consultationId = $this->createSegmentedSession();
        $this->upload($consultationId, 1, 'one');
        $this->upload($consultationId, 2, 'two', true);
        $consultation = Consultation::findOrFail($consultationId);
        $consultation->update([
            'recording_finished_at' => now(),
            'expected_segments' => 2,
            'transcription_strategy' => 'single',
            'transcription_status' => 'queued',
        ]);
        $firstPath = ConsultationAudioSegment::query()->orderBy('segment_number')->value('storage_path');
        $this->mock(AudioSegmentConsolidator::class, function (MockInterface $mock) use ($consultation, $firstPath): void {
            $mock->shouldReceive('consolidate')->once()->withArgs(fn ($value) => $value->id === $consultation->id)->andReturn($firstPath);
        });
        $this->mock(OpenAIClinicalAssistant::class, function (MockInterface $mock): void {
            $mock->shouldReceive('transcribe')->once()->andReturn([
                'text' => 'Paciente niega fiebre.',
                'model' => 'test-transcription-model',
            ]);
        });

        (new TranscribeConsultationAudioJob($consultationId))->handle(
            app(AudioSegmentConsolidator::class),
            app(OpenAIClinicalAssistant::class)
        );

        $this->assertDatabaseHas('consultations', [
            'id' => $consultationId,
            'transcription_text' => 'Paciente niega fiebre.',
            'transcribed_segments' => 2,
            'transcription_status' => 'completed',
            'processing_status' => 'generating_soap',
        ]);
        $this->assertSame(2, ConsultationAudioSegment::where('transcription_status', 'completed')->count());
        Queue::assertPushed(GenerateSoapJob::class);
        Queue::assertNotPushed(TranscribeAudioSegmentJob::class);
    }

    public function test_finalizer_recovers_soap_dispatch_after_saved_single_transcription(): void
    {
        $consultationId = $this->createSegmentedSession();
        $this->upload($consultationId, 1, 'one', true);
        Consultation::findOrFail($consultationId)->update([
            'recording_finished_at' => now(),
            'expected_segments' => 1,
            'transcription_strategy' => 'single',
            'transcription_status' => 'completed',
            'transcription_text' => 'Transcripción ya guardada.',
            'soap_status' => 'pending',
            'processing_status' => 'transcribing',
        ]);

        (new FinalizeConsultationJob($consultationId))->handle();

        Queue::assertPushed(GenerateSoapJob::class);
        Queue::assertNotPushed(TranscribeConsultationAudioJob::class);
    }

    public function test_consolidation_failure_switches_to_segmented_transcription(): void
    {
        $consultationId = $this->createSegmentedSession();
        $this->upload($consultationId, 1, 'one');
        $this->upload($consultationId, 2, 'two', true);
        Consultation::findOrFail($consultationId)->update([
            'recording_finished_at' => now(),
            'expected_segments' => 2,
            'transcription_strategy' => 'single',
            'transcription_status' => 'queued',
        ]);
        $this->mock(AudioSegmentConsolidator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('consolidate')->once()->andThrow(new \RuntimeException('ffmpeg unavailable'));
        });

        (new TranscribeConsultationAudioJob($consultationId))->handle(
            app(AudioSegmentConsolidator::class),
            app(OpenAIClinicalAssistant::class)
        );

        $this->assertDatabaseHas('consultations', [
            'id' => $consultationId,
            'transcription_strategy' => 'segmented',
            'transcription_status' => 'pending',
        ]);
        Queue::assertPushed(FinalizeConsultationJob::class);

        (new FinalizeConsultationJob($consultationId))->handle();
        Queue::assertPushedTimes(TranscribeAudioSegmentJob::class, 2);
    }

    public function test_consolidator_creates_and_reuses_a_single_audio_file(): void
    {
        $consultationId = $this->createSegmentedSession();
        $this->upload($consultationId, 1, 'one');
        $this->upload($consultationId, 2, 'two', true);
        $fakeFfmpeg = tempnam(sys_get_temp_dir(), 'fake-ffmpeg-');
        file_put_contents($fakeFfmpeg, <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents($argv[count($argv) - 1], 'merged-audio');
PHP);
        chmod($fakeFfmpeg, 0700);
        config([
            'services.openai.ffmpeg_binary' => $fakeFfmpeg,
            'services.openai.ffmpeg_timeout' => 5,
        ]);

        try {
            $consultation = Consultation::findOrFail($consultationId);
            $consolidator = app(AudioSegmentConsolidator::class);
            $path = $consolidator->consolidate($consultation);

            Storage::disk('local')->assertExists($path);
            $this->assertSame('merged-audio', Storage::disk('local')->get($path));
            $this->assertSame($path, $consolidator->consolidate($consultation));
            Storage::disk('local')->assertMissing('consultations/'.$this->sessionUuid.'/consolidated/segments.txt');
        } finally {
            @unlink($fakeFfmpeg);
        }
    }

    public function test_soap_generation_can_recover_without_retranscribing(): void
    {
        $consultationId = $this->createSegmentedSession();
        $consultation = Consultation::findOrFail($consultationId);
        $consultation->update([
            'transcription_text' => 'Paciente niega fiebre.',
            'processing_status' => 'failed',
            'soap_status' => 'failed',
        ]);
        $this->withToken($this->token)
            ->postJson("/api/consultations/$consultationId/retry-processing")
            ->assertAccepted()
            ->assertJsonPath('status', 'processing');
        Queue::assertPushed(GenerateSoapJob::class);
        $this->mock(OpenAIClinicalAssistant::class, function (MockInterface $mock): void {
            $mock->shouldReceive('draftConsultation')->once()->andReturn([
                'draft' => [
                    'reason' => 'Control',
                    'subjective' => 'Niega fiebre.',
                    'objective' => 'no especificado',
                    'assessment' => 'no especificado',
                    'plan' => 'no especificado',
                    'vital_signs' => [],
                ],
                'model' => 'test-soap-model',
                'usage' => [],
            ]);
        });

        (new GenerateSoapJob($consultationId))->handle(app(OpenAIClinicalAssistant::class));

        $this->assertDatabaseHas('consultations', [
            'id' => $consultationId,
            'processing_status' => 'completed',
            'soap_status' => 'completed',
            'subjective' => 'Niega fiebre.',
        ]);
        $this->assertDatabaseCount('consultation_audio_segments', 0);
    }

    private function createSegmentedSession(?string $uuid = null): int
    {
        $uuid ??= $this->sessionUuid;

        return (int) $this->withToken($this->token)->postJson('/api/consultations/start', [
            'patient_id' => $this->patientId,
            'session_uuid' => $uuid,
        ])->assertCreated()->json('consultation_id');
    }

    private function upload(int $consultation, int $number, string $seed, bool $final = false, ?string $uuid = null)
    {
        $file = UploadedFile::fake()
            ->createWithContent("$seed.m4a", "\0\0\0\x18ftypM4A \0\0\0\0$seed")
            ->mimeType('audio/mp4');

        return $this->postSegment($consultation, $file, $number, $final, $uuid);
    }

    private function postSegment(int $consultation, UploadedFile $file, int $number, bool $final = false, ?string $uuid = null)
    {
        return $this->withToken($this->token)->post("/api/consultations/$consultation/segments", [
            'audio' => $file,
            'session_uuid' => $uuid ?? $this->sessionUuid,
            'segment_number' => $number,
            'duration_seconds' => $final ? 12 : 60,
            'is_final' => $final,
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);
    }
}
