<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\SoapEvaluation;
use App\Services\SoapEvaluationExporter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SPSS\Sav\Reader as SavReader;
use Tests\TestCase;

class ConsultationFailureTrackingTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Queue::fake();
        $this->token = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Evaluadora',
            'email' => 'failure@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');
        $this->patientId = $this->withToken($this->token)->postJson('/api/patients', [
            'first_name' => 'Paciente', 'last_name' => 'Prueba', 'dni' => '0801199018888',
        ])->json('patient.id');
    }

    public function test_consultation_code_evaluation_and_attempt_exist_before_recording(): void
    {
        $response = $this->start();
        $id = $response->json('consultation_id');

        $response->assertCreated()->assertJsonPath('consultation_code', fn ($code) => str_starts_with($code, 'C-'));
        $this->assertDatabaseHas('consultations', ['id' => $id, 'overall_status' => 'recording', 'is_evaluable' => 1]);
        $this->assertDatabaseHas('consultation_processing_attempts', ['consultation_id' => $id, 'attempt_number' => 1]);
        $this->assertDatabaseHas('soap_evaluations', ['consultation_id' => $id, 'status' => 'pending']);
    }

    public function test_failed_consultation_remains_in_history_and_is_evaluable_without_soap(): void
    {
        $id = $this->start()->json('consultation_id');
        $this->withToken($this->token)->postJson("/api/consultations/$id/failure", [
            'failure_stage' => 'recording',
            'failure_code' => 'MICROPHONE_START_FAILED',
            'failure_message' => 'StateError',
        ])->assertOk();

        $this->withToken($this->token)->getJson('/api/consultations')
            ->assertOk()
            ->assertJsonPath('consultations.0.id', $id)
            ->assertJsonPath('consultations.0.overall_status', 'failed')
            ->assertJsonPath('consultations.0.failure_stage', 'recording');

        $evaluation = $this->withToken($this->token)->getJson("/api/consultations/$id/soap-evaluation")
            ->assertOk()
            ->assertJsonPath('evaluation.consultation.soap_status', 'pending');
        $evaluationId = $evaluation->json('evaluation.id');
        $payload = ['version' => 1, 'manual_time_seconds' => 60];
        foreach (['use_prototype', 'audio_transcription', 'clinical_processing', 'soap_generation'] as $field) {
            $payload[$field] = $field === 'use_prototype' || $field === 'consultation_registered' ? 1 : 0;
        }
        foreach (range(1, 6) as $number) {
            $payload["utility_$number"] = 3;
            $payload["ease_$number"] = 3;
        }

        $this->withToken($this->token)->postJson("/api/soap-evaluations/$evaluationId/complete", $payload)
            ->assertOk()
            ->assertJsonPath('evaluation.status', 'completed')
            ->assertJsonPath('evaluation.soap_subjective', 98)
            ->assertJsonPath('evaluation.error_transcription', 98)
            ->assertJsonPath('evaluation.evaluation_result_type', 'technical_failure');

        $completed = SoapEvaluation::with(['consultation', 'processingAttempt'])->whereKey($evaluationId)->get();
        $exporter = app(SoapEvaluationExporter::class);
        $csvPath = $exporter->export($completed, 'csv');
        $lines = file($csvPath, FILE_IGNORE_NEW_LINES);
        $headers = str_getcsv($lines[0]);
        $row = array_combine($headers, str_getcsv($lines[1]));
        $this->assertSame('', $row['soap_subjetivo']);
        $this->assertSame('', $row['err_transcripcion']);
        $this->assertSame('0', $row['soap_generado']);
        $this->assertSame('technical_failure', $row['tipo_resultado_evaluacion']);
        @unlink($csvPath);

        $savPath = $exporter->export($completed, 'sav');
        $sav = SavReader::fromFile($savPath)->read();
        $errorIndex = array_search('err_transcripcion', array_keys($exporter->variables()), true);
        $this->assertSame(1, $sav->variables[$errorIndex]->missingValuesFormat);
        $this->assertSame([98.0], $sav->variables[$errorIndex]->missingValues);
        @unlink($savPath);
    }

    public function test_retry_preserves_failed_attempt(): void
    {
        $id = $this->start()->json('consultation_id');
        $this->withToken($this->token)->postJson("/api/consultations/$id/failure", [
            'failure_stage' => 'transcription', 'failure_code' => 'AI_TIMEOUT', 'failure_message' => 'TimeoutException',
        ])->assertOk();
        $this->withToken($this->token)->postJson("/api/consultations/$id/retry-processing")->assertAccepted();

        $this->assertDatabaseHas('consultation_processing_attempts', ['consultation_id' => $id, 'attempt_number' => 1, 'result' => 'failed']);
        $this->assertDatabaseHas('consultation_processing_attempts', ['consultation_id' => $id, 'attempt_number' => 2, 'result' => 'pending']);
        $this->assertSame(2, Consultation::findOrFail($id)->last_processing_attempt);
    }

    public function test_doctor_can_cancel_pending_audio_processing(): void
    {
        $id = $this->start()->json('consultation_id');

        $this->withToken($this->token)
            ->postJson("/api/consultations/$id/cancel-processing")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('consultations', [
            'id' => $id,
            'processing_status' => 'cancelled',
            'overall_status' => 'cancelled',
        ]);
    }

    public function test_failed_consultation_is_included_in_research_export(): void
    {
        $id = $this->start()->json('consultation_id');
        $this->withToken($this->token)->postJson("/api/consultations/$id/failure", [
            'failure_stage' => 'segment_upload', 'failure_code' => 'UPLOAD_FAILED', 'failure_message' => 'SocketException',
        ])->assertOk();
        $evaluation = SoapEvaluation::with(['consultation', 'processingAttempt'])->where('consultation_id', $id)->get();
        $path = app(SoapEvaluationExporter::class)->export($evaluation, 'csv');
        $contents = file_get_contents($path);

        $this->assertStringContainsString('codigo_consulta', $contents);
        $this->assertStringContainsString('estado_general', $contents);
        $this->assertStringContainsString('segment_upload', $contents);
        @unlink($path);
    }

    private function start()
    {
        return $this->withToken($this->token)->postJson('/api/consultations/start', [
            'patient_id' => $this->patientId,
            'session_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'local_consultation_code' => 'LOCAL-20260713-550E8400',
            'created_offline' => true,
        ]);
    }
}
