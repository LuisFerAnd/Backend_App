<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Patient;
use App\Models\SoapEvaluation;
use App\Models\User;
use App\Services\SoapEvaluationCalculator;
use App\Services\SoapEvaluationFactory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SoapEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_daily_code_is_generated_and_one_evaluation_per_consultation_is_enforced(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');
        [$doctor, $first] = $this->consultation();
        [, $second] = $this->consultation($doctor);
        $factory = app(SoapEvaluationFactory::class);

        $one = $factory->firstOrCreate($first, $doctor);
        $same = $factory->firstOrCreate($first, $doctor);
        $two = $factory->firstOrCreate($second, $doctor);

        $this->assertSame('C-10-07-2026-001', $one->test_code);
        $this->assertSame($one->id, $same->id);
        $this->assertSame('C-10-07-2026-002', $two->test_code);
        $this->assertSame(2, SoapEvaluation::count());

        Carbon::setTestNow('2026-07-11 09:00:00');
        [, $third] = $this->consultation($doctor);
        $this->assertSame('C-11-07-2026-001', $factory->firstOrCreate($third, $doctor)->test_code);
    }

    public function test_calculations_keep_scales_separate_and_count_error_levels(): void
    {
        $data = ['manual_time_seconds' => 600, 'ai_time_seconds' => 125];
        foreach (SoapEvaluationCalculator::SOAP as $key) {
            $data[$key] = 3;
        }
        foreach (SoapEvaluationCalculator::ERRORS as $index => $key) {
            $data[$key] = [1, 2, 2, 3, 4, 5][$index];
        }
        foreach (SoapEvaluationCalculator::UTILITY as $key) {
            $data[$key] = 5;
        }
        foreach (SoapEvaluationCalculator::EASE as $key) {
            $data[$key] = 3;
        }

        $result = app(SoapEvaluationCalculator::class)->calculate($data);
        $this->assertSame(475, $result['time_difference_seconds']);
        $this->assertSame(18, $result['soap_total']);
        $this->assertSame(100.0, $result['soap_percentage']);
        $this->assertSame(17, $result['error_total']);
        $this->assertSame([1, 2, 1, 1, 1], [$result['error_totally_wrong_count'], $result['error_severe_count'], $result['error_moderate_count'], $result['error_mild_count'], $result['error_none_count']]);
        $this->assertSame(30, $result['utility_total']);
        $this->assertSame(5.0, $result['utility_average']);
        $this->assertSame(18, $result['ease_total']);
        $this->assertSame(3.0, $result['ease_average']);
    }

    public function test_draft_version_conflict_completion_validation_and_export_authorization(): void
    {
        [$doctor, $consultation] = $this->consultation();
        $doctor->assignRole('doctor');
        $token = $this->login($doctor);
        $created = $this->withToken($token)->getJson("/api/consultations/{$consultation->id}/soap-evaluation")->assertOk();
        $id = $created->json('evaluation.id');

        $this->withToken($token)->putJson("/api/soap-evaluations/$id", ['version' => 1, 'use_prototype' => 1])->assertOk()->assertJsonPath('evaluation.status', 'draft')->assertJsonPath('evaluation.version', 2);
        $this->withToken($token)->putJson("/api/soap-evaluations/$id", ['version' => 1, 'use_prototype' => 0])->assertStatus(409);
        $this->withToken($token)->postJson("/api/soap-evaluations/$id/complete", ['version' => 2])->assertUnprocessable();

        $complete = ['version' => 2, 'manual_time_seconds' => 300];
        foreach (SoapEvaluationCalculator::BINARY as $field) {
            $complete[$field] = 1;
        }
        foreach (SoapEvaluationCalculator::SOAP as $field) {
            $complete[$field] = 3;
        }
        foreach (SoapEvaluationCalculator::ERRORS as $field) {
            $complete[$field] = 5;
        }
        foreach ([...SoapEvaluationCalculator::UTILITY, ...SoapEvaluationCalculator::EASE] as $field) {
            $complete[$field] = 4;
        }

        $this->withToken($token)
            ->postJson("/api/soap-evaluations/$id/complete", $complete)
            ->assertOk()
            ->assertJsonPath('evaluation.status', 'completed')
            ->assertJsonPath('evaluation.test_code', $created->json('evaluation.test_code'))
            ->assertJsonPath('evaluation.test_date', $created->json('evaluation.test_date'));

        $this->withToken($token)->get('/api/admin/soap-evaluations/export/csv')->assertForbidden();
    }

    public function test_admin_exports_valid_filtered_csv_and_xlsx_without_patient_data(): void
    {
        [$doctor, $consultation] = $this->consultation();
        $evaluation = app(SoapEvaluationFactory::class)->firstOrCreate($consultation, $doctor);
        $evaluation->update([
            'status' => 'completed',
            'error_transcription' => 5,
            'error_omission' => 5,
            'error_added' => 5,
            'error_confusion' => 5,
            'error_placement' => 5,
            'error_wording' => 5,
            'error_total' => 30,
            'error_totally_wrong_count' => 0,
            'error_none_count' => 6,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $token = $this->login($admin);

        $csv = $this->withToken($token)->get('/api/admin/soap-evaluations/export/csv?status=completed')->assertOk();
        $this->assertStringContainsString('codigo_prueba', $csv->streamedContent());
        $this->assertStringNotContainsString($consultation->patient->dni, $csv->streamedContent());

        $xlsx = $this->withToken($token)->get('/api/admin/soap-evaluations/export/xlsx?status=completed')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $xlsx->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);
        $this->assertSame(['Datos', 'Diccionario'], $book->getSheetNames());
        $sheet = $book->getSheetByName('Datos');
        $headers = array_flip($sheet->rangeToArray('A1:AZ1')[0]);
        $this->assertSame(5, $sheet->getCell([$headers['err_transcripcion'] + 1, 2])->getValue());
        $this->assertSame(30, $sheet->getCell([$headers['err_total'] + 1, 2])->getValue());
        $this->assertSame(0, $sheet->getCell([$headers['err_totalmente_erroneos'] + 1, 2])->getValue());
        $this->assertSame(6, $sheet->getCell([$headers['err_sin_error'] + 1, 2])->getValue());

        $sav = $this->withToken($token)->get('/api/admin/soap-evaluations/export/sav?status=completed')->assertOk();
        $this->assertGreaterThan(500, strlen($sav->streamedContent()));
    }

    public function test_admin_can_only_view_the_doctors_existing_evaluation(): void
    {
        [$doctor, $consultation] = $this->consultation();
        $doctor->assignRole('doctor');
        $evaluation = app(SoapEvaluationFactory::class)->firstOrCreate($consultation, $doctor);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $token = $this->login($admin);

        $this->withToken($token)
            ->getJson("/api/consultations/{$consultation->id}/soap-evaluation")
            ->assertOk()
            ->assertJsonPath('evaluation.id', $evaluation->id)
            ->assertJsonPath('evaluation.evaluator_id', $doctor->id)
            ->assertJsonPath('evaluation.evaluator_name', $doctor->name);

        $this->withToken($token)
            ->putJson("/api/soap-evaluations/{$evaluation->id}", ['version' => 1, 'use_prototype' => 1])
            ->assertForbidden();
        $this->withToken($token)
            ->postJson("/api/soap-evaluations/{$evaluation->id}/complete", ['version' => 1])
            ->assertForbidden();
    }

    public function test_admin_does_not_create_an_evaluation_when_the_doctor_has_not_made_one(): void
    {
        [, $consultation] = $this->consultation();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->withToken($this->login($admin))
            ->getJson("/api/consultations/{$consultation->id}/soap-evaluation")
            ->assertNotFound();

        $this->assertDatabaseMissing('soap_evaluations', [
            'consultation_id' => $consultation->id,
            'evaluator_id' => $admin->id,
        ]);
    }

    private function consultation(?User $doctor = null): array
    {
        $doctor ??= User::factory()->create(['specialization' => 'Medicina interna']);
        $patient = Patient::create(['doctor_id' => $doctor->id, 'first_name' => 'Paciente', 'last_name' => 'Prueba', 'dni' => fake()->unique()->numerify('#############')]);
        $consultation = Consultation::create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'consulted_at' => now(), 'subjective' => 'S', 'objective' => 'O', 'assessment' => 'A', 'plan' => 'P', 'vital_signs' => ['audio_duration_seconds' => 85, 'ai_generation_seconds' => 12]]);

        return [$doctor, $consultation];
    }

    private function login(User $user): string
    {
        $user->update(['password' => 'password']);

        return $this->postJson('/api/doctors/login', ['email' => $user->email, 'password' => 'password'])->json('token');
    }
}
