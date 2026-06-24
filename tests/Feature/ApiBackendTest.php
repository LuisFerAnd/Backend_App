<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
