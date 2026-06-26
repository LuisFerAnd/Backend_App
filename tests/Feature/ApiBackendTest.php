<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_register_create_patient_and_register_soap_consultation(): void
    {
        $registerResponse = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Ana Lopez',
            'email' => 'ana@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $registerResponse
            ->assertCreated()
            ->assertJsonStructure([
                'doctor' => ['id', 'name', 'email'],
                'token',
                'token_type',
            ]);

        $token = $registerResponse->json('token');

        $patientResponse = $this
            ->withToken($token)
            ->postJson('/api/patients', [
                'first_name' => 'Carlos',
                'last_name' => 'Martinez',
                'dni' => '0801199012345',
            ]);

        $patientResponse
            ->assertCreated()
            ->assertJsonPath('patient.dni', '0801199012345');

        $consultationResponse = $this
            ->withToken($token)
            ->postJson('/api/consultations', [
                'patient_id' => $patientResponse->json('patient.id'),
                'reason' => 'Dolor de garganta',
                'subjective' => 'Paciente refiere dolor desde hace dos dias.',
                'objective' => 'Faringe eritematosa, sin dificultad respiratoria.',
                'assessment' => 'Faringitis aguda probable.',
                'plan' => 'Hidratacion, analgesico y reevaluacion si empeora.',
                'vital_signs' => [
                    'temperature' => 37.8,
                    'heart_rate' => 82,
                ],
            ]);

        $consultationResponse
            ->assertCreated()
            ->assertJsonPath('consultation.subjective', 'Paciente refiere dolor desde hace dos dias.')
            ->assertJsonPath('consultation.patient.dni', '0801199012345');
    }

    public function test_doctor_records_are_scoped_to_each_doctor(): void
    {
        $firstDoctorToken = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Ana Lopez',
            'email' => 'ana@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');

        $secondDoctorToken = $this->postJson('/api/doctors/register', [
            'name' => 'Dr. Jose Rivera',
            'email' => 'jose@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');

        $this
            ->withToken($firstDoctorToken)
            ->postJson('/api/patients', [
                'first_name' => 'Carlos',
                'last_name' => 'Martinez',
                'dni' => '0801199012345',
            ])
            ->assertCreated();

        $this
            ->withToken($secondDoctorToken)
            ->postJson('/api/patients', [
                'first_name' => 'Pedro',
                'last_name' => 'Alvarez',
                'dni' => '0801199012345',
            ])
            ->assertCreated();

        $this
            ->withToken($firstDoctorToken)
            ->getJson('/api/patients')
            ->assertOk()
            ->assertJsonCount(1, 'patients')
            ->assertJsonPath('patients.0.first_name', 'Carlos');

        $this
            ->withToken($secondDoctorToken)
            ->getJson('/api/patients')
            ->assertOk()
            ->assertJsonCount(1, 'patients')
            ->assertJsonPath('patients.0.first_name', 'Pedro');
    }

    public function test_admin_can_see_all_records_and_doctor_cannot_use_admin_routes(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.test',
        ]);

        $admin->assignRole(Role::findOrCreate('admin', 'web'));

        $adminToken = $this->postJson('/api/doctors/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
        ])->json('token');

        $doctorResponse = $this->postJson('/api/doctors/register', [
            'name' => 'Dr. Jose Rivera',
            'email' => 'jose@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $doctorToken = $doctorResponse->json('token');

        $patientResponse = $this
            ->withToken($doctorToken)
            ->postJson('/api/patients', [
                'first_name' => 'Maria',
                'last_name' => 'Hernandez',
                'dni' => '0801200012345',
            ]);

        $this
            ->withToken($doctorToken)
            ->postJson('/api/consultations', [
                'patient_id' => $patientResponse->json('patient.id'),
                'subjective' => 'Consulta de seguimiento.',
                'objective' => 'Paciente estable.',
                'assessment' => 'Evolucion favorable.',
                'plan' => 'Continuar tratamiento.',
            ])
            ->assertCreated();

        $this
            ->withToken($adminToken)
            ->getJson('/api/admin/summary')
            ->assertOk()
            ->assertJsonPath('summary.doctors', 1)
            ->assertJsonPath('summary.admins', 1)
            ->assertJsonPath('summary.patients', 1)
            ->assertJsonPath('summary.consultations', 1);

        $this
            ->withToken($adminToken)
            ->getJson('/api/admin/consultations')
            ->assertOk()
            ->assertJsonPath('consultations.data.0.patient.dni', '0801200012345')
            ->assertJsonPath('consultations.data.0.doctor.email', 'jose@example.test');

        $this
            ->withToken($doctorToken)
            ->getJson('/api/admin/summary')
            ->assertForbidden();
    }
}
