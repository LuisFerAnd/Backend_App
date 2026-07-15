<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use App\Services\ProcessingTimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProcessingTimeTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_persists_exact_time_and_backend_classification(): void
    {
        $consultation = $this->consultation();
        $service = app(ProcessingTimeService::class);
        $service->startProcessing($consultation, Carbon::parse('2026-07-15 10:00:00.000'));
        $result = $service->finishSuccessfully($consultation, Carbon::parse('2026-07-15 10:00:48.400'));

        $this->assertSame(48400, $result->processing_time_ms);
        $this->assertSame('48.400', $result->processing_time_seconds);
        $this->assertSame(4, $result->processing_time_range);
        $this->assertSame('Rápido', $result->processing_time_label);
        $this->assertSame('completed', $result->processing_status);
        $this->assertTrue($result->soap_generated);
    }

    public function test_error_and_timeout_persist_time_and_keep_consultation(): void
    {
        $service = app(ProcessingTimeService::class);
        $failed = $this->consultation();
        $service->startProcessing($failed, Carbon::parse('2026-07-15 10:00:00.000'));
        $failed = $service->finishWithError(
            $failed,
            'OPENAI_ERROR',
            'RuntimeException',
            'soap_generation',
            finishedAt: Carbon::parse('2026-07-15 10:01:13.200'),
        );

        $this->assertSame('failed', $failed->processing_status);
        $this->assertSame(73200, $failed->processing_time_ms);
        $this->assertSame(3, $failed->processing_time_range);
        $this->assertFalse($failed->soap_generated);
        $this->assertNotNull(Consultation::find($failed->id));

        $timeout = $this->consultation();
        $service->startProcessing($timeout, Carbon::parse('2026-07-15 11:00:00.000'));
        $timeout = $service->finishWithError(
            $timeout,
            'OPENAI_TIMEOUT',
            'Timeout exceeded',
            'soap_generation',
            'timeout',
            Carbon::parse('2026-07-15 11:03:00.001'),
        );

        $this->assertSame('timeout', $timeout->processing_status);
        $this->assertSame(180001, $timeout->processing_time_ms);
        $this->assertSame(1, $timeout->processing_time_range);
    }

    public function test_client_cannot_write_official_processing_fields(): void
    {
        $doctor = User::factory()->create(['password' => 'password']);
        $consultation = $this->consultation($doctor);
        $token = $this->postJson('/api/doctors/login', [
            'email' => $doctor->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)->putJson('/api/consultations/'.$consultation->id, [
            'processing_time_range' => 5,
            'processing_time_label' => 'Muy lento',
        ])->assertOk();

        $consultation->refresh();
        $this->assertNull($consultation->processing_time_range);
        $this->assertNull($consultation->processing_time_label);
    }

    private function consultation(?User $doctor = null): Consultation
    {
        $doctor ??= User::factory()->create();
        $patient = Patient::create([
            'doctor_id' => $doctor->id,
            'first_name' => 'Paciente',
            'last_name' => 'Tiempo',
            'dni' => fake()->unique()->numerify('#############'),
        ]);

        return Consultation::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'consulted_at' => now(),
            'subjective' => 'S',
            'objective' => 'O',
            'assessment' => 'A',
            'plan' => 'P',
            'processing_status' => 'pending',
        ]);
    }
}
