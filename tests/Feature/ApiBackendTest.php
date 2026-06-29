<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

    public function test_doctor_can_transcribe_consultation_audio(): void
    {
        config(['services.openai.key' => 'test-openai-key']);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(
                'Paciente refiere dolor de garganta desde hace dos dias.',
                200,
                ['Content-Type' => 'text/plain']
            ),
        ]);

        $token = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Ana Lopez',
            'email' => 'ana@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');

        $this
            ->withToken($token)
            ->post('/api/ai/transcriptions', [
                'audio' => UploadedFile::fake()->create('consulta.mp3', 512, 'audio/mpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('transcript', 'Paciente refiere dolor de garganta desde hace dos dias.')
            ->assertJsonPath('model', 'gpt-4o-mini-transcribe');
    }

    public function test_transcription_returns_clear_message_when_audio_has_no_detected_voice(): void
    {
        config(['services.openai.key' => 'test-openai-key']);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => '',
            ]),
        ]);

        $token = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Ana Lopez',
            'email' => 'ana@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');

        $this
            ->withToken($token)
            ->post('/api/ai/transcriptions', [
                'audio' => UploadedFile::fake()->create('consulta.mp3', 512, 'audio/mpeg'),
            ])
            ->assertStatus(502)
            ->assertJsonPath('message', 'No se detecto voz en el audio. Revisa que el microfono haya grabado sonido y vuelve a intentarlo.');
    }

    public function test_doctor_can_generate_consultation_draft_from_transcript(): void
    {
        config(['services.openai.key' => 'test-openai-key']);
        config([
            'services.openai.pricing.soap_input_cost_per_1m' => 0.75,
            'services.openai.pricing.soap_output_cost_per_1m' => 4.50,
            'services.openai.pricing.transcription_cost_per_minute' => 0.003,
        ]);

        $draft = [
            'reason' => 'Dolor de garganta',
            'subjective' => 'Paciente refiere dolor de garganta desde hace dos dias.',
            'objective' => '',
            'assessment' => '',
            'plan' => '',
            'vital_signs' => [
                'temperature' => null,
                'blood_pressure' => null,
                'heart_rate' => null,
                'respiratory_rate' => null,
                'oxygen_saturation' => null,
                'weight' => null,
                'height' => null,
                'other' => [],
            ],
        ];

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => json_encode($draft),
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 80,
                ],
            ]),
        ]);

        $token = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Ana Lopez',
            'email' => 'ana@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');

        $patientId = $this
            ->withToken($token)
            ->postJson('/api/patients', [
                'first_name' => 'Carlos',
                'last_name' => 'Martinez',
                'dni' => '0801199012345',
            ])
            ->json('patient.id');

        $this
            ->withToken($token)
            ->postJson('/api/ai/consultation-draft', [
                'patient_id' => $patientId,
                'transcript' => 'Paciente refiere dolor de garganta desde hace dos dias.',
            ])
            ->assertOk()
            ->assertJsonPath('draft.reason', 'Dolor de garganta')
            ->assertJsonPath('draft.objective', 'no especificado')
            ->assertJsonPath('draft.assessment', 'no especificado')
            ->assertJsonPath('draft.plan', 'no especificado')
            ->assertJsonPath('draft.vital_signs.temperature', 'no especificado')
            ->assertJsonPath('cost.soap_input_tokens', 120)
            ->assertJsonPath('cost.soap_output_tokens', 80)
            ->assertJsonPath('cost.estimated_soap_cost_usd', 0.00045)
            ->assertJsonPath('models.soap_formatter', 'gpt-5.4-nano');
    }

    public function test_doctor_can_generate_consultation_draft_directly_from_audio(): void
    {
        config(['services.openai.key' => 'test-openai-key']);

        $draft = [
            'reason' => 'Fiebre',
            'subjective' => 'Paciente refiere fiebre desde anoche.',
            'objective' => 'Temperatura 38.5 grados.',
            'assessment' => '',
            'plan' => 'Tomar acetaminofen e hidratarse.',
            'vital_signs' => [
                'temperature' => '38.5',
                'blood_pressure' => null,
                'heart_rate' => null,
                'respiratory_rate' => null,
                'oxygen_saturation' => null,
                'weight' => null,
                'height' => null,
                'other' => [],
            ],
        ];

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Paciente refiere fiebre desde anoche y temperatura 38.5. Tomar acetaminofen e hidratarse.',
            ]),
            'api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => json_encode($draft),
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 130,
                    'output_tokens' => 90,
                ],
            ]),
        ]);

        $token = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Ana Lopez',
            'email' => 'ana@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');

        $patientId = $this
            ->withToken($token)
            ->postJson('/api/patients', [
                'first_name' => 'Carlos',
                'last_name' => 'Martinez',
                'dni' => '0801199012345',
            ])
            ->json('patient.id');

        $this
            ->withToken($token)
            ->post('/api/ai/consultation-draft', [
                'patient_id' => $patientId,
                'audio' => UploadedFile::fake()->create('consulta.m4a', 512, 'audio/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('transcript', 'Paciente refiere fiebre desde anoche y temperatura 38.5. Tomar acetaminofen e hidratarse.')
            ->assertJsonPath('draft.reason', 'Fiebre')
            ->assertJsonPath('draft.subjective', 'Paciente refiere fiebre desde anoche.')
            ->assertJsonPath('draft.assessment', 'no especificado')
            ->assertJsonPath('draft.plan', 'Tomar acetaminofen e hidratarse.')
            ->assertJsonPath('draft.vital_signs.temperature', '38.5')
            ->assertJsonPath('models.transcription', 'gpt-4o-mini-transcribe')
            ->assertJsonPath('models.soap_formatter', 'gpt-5.4-nano');
    }

    public function test_doctor_can_generate_structured_consultation_draft_with_all_soap_subfields(): void
    {
        config(['services.openai.key' => 'test-openai-key']);

        $draft = [
            'reason' => 'Fiebre y dolor de garganta',
            'subjective' => [
                'chief_complaint' => 'Fiebre y dolor de garganta',
                'history_of_present_illness' => 'Desde anoche.',
                'symptoms' => "- Fiebre\n- Dolor de garganta",
                'pertinent_negatives' => 'Niega dificultad respiratoria.',
                'exposures' => 'no especificado',
                'past_medical_history' => 'no especificado',
                'medications' => 'no especificado',
                'allergies' => 'no especificado',
            ],
            'objective' => [
                'physical_exam' => 'Faringe eritematosa.',
                'measurable_findings' => 'no especificado',
                'test_results' => 'no especificado',
                'vital_signs' => [
                    'temperature' => '38.5 grados',
                    'blood_pressure' => 'no especificado',
                    'heart_rate' => 'no especificado',
                    'respiratory_rate' => 'no especificado',
                    'oxygen_saturation' => 'no especificado',
                    'weight' => 'no especificado',
                    'height' => 'no especificado',
                    'other' => 'no especificado',
                ],
            ],
            'assessment' => [
                'impressions' => 'Cuadro compatible con faringitis.',
                'diagnoses' => 'no especificado',
                'clinical_reasoning' => 'no especificado',
            ],
            'plan' => [
                'medications' => 'Acetaminofen.',
                'tests' => 'no especificado',
                'procedures' => 'no especificado',
                'recommendations' => 'Hidratacion y reposo.',
                'follow_up' => 'Regresar si empeora.',
                'return_precautions' => 'Consultar si presenta dificultad respiratoria.',
                'patient_education' => 'no especificado',
            ],
        ];

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => json_encode($draft),
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 180,
                    'output_tokens' => 220,
                ],
            ]),
        ]);

        $token = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Ana Lopez',
            'email' => 'ana@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');

        $response = $this
            ->withToken($token)
            ->postJson('/api/ai/consultation-draft', [
                'transcript' => 'Fiebre y dolor de garganta desde anoche. Niega dificultad respiratoria. Faringe eritematosa. Temperatura 38.5 grados. Cuadro compatible con faringitis. Acetaminofen, hidratacion y reposo. Regresar si empeora.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('draft.reason', 'Fiebre y dolor de garganta')
            ->assertJsonPath('draft.structured.subjective.exposures', 'no especificado')
            ->assertJsonPath('draft.structured.objective.vital_signs.blood_pressure', 'no especificado')
            ->assertJsonPath('draft.vital_signs.temperature', '38.5 grados');

        $this->assertStringContainsString('Sintomas:', $response->json('draft.subjective'));
        $this->assertStringContainsString('- Fiebre', $response->json('draft.subjective'));
        $this->assertStringContainsString('Exposiciones: no especificado', $response->json('draft.subjective'));
        $this->assertStringContainsString('Presion arterial: no especificado', $response->json('draft.objective'));
        $this->assertStringContainsString('Diagnosticos: no especificado', $response->json('draft.assessment'));
        $this->assertStringContainsString('Pruebas solicitadas: no especificado', $response->json('draft.plan'));

        Http::assertSent(function ($request) {
            $schema = data_get($request->data(), 'text.format.schema');

            return str_ends_with($request->url(), '/responses')
                && data_get($schema, 'properties.subjective.type') === 'object'
                && data_get($schema, 'properties.objective.properties.vital_signs.type') === 'object'
                && data_get($schema, 'properties.plan.required') === [
                    'medications',
                    'tests',
                    'procedures',
                    'recommendations',
                    'follow_up',
                    'return_precautions',
                    'patient_education',
                ]
                && ! array_key_exists('vital_signs', data_get($schema, 'properties', []));
        });
    }

    public function test_doctor_can_export_consultation_ai_costs_as_csv(): void
    {
        $token = $this->postJson('/api/doctors/register', [
            'name' => 'Dra. Ana Lopez',
            'email' => 'ana@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('token');

        $patientId = $this
            ->withToken($token)
            ->postJson('/api/patients', [
                'first_name' => 'Carlos',
                'last_name' => 'Martinez',
                'dni' => '0801199012345',
            ])
            ->json('patient.id');

        $this
            ->withToken($token)
            ->postJson('/api/consultations', [
                'patient_id' => $patientId,
                'reason' => 'Fiebre',
                'subjective' => 'Paciente refiere fiebre.',
                'objective' => 'Temperatura 38.5.',
                'assessment' => 'Cuadro viral probable.',
                'plan' => 'Hidratacion y acetaminofen.',
                'vital_signs' => [
                    'ai_usage' => [
                        'currency' => 'USD',
                        'transcription_model' => 'gpt-4o-mini-transcribe',
                        'soap_model' => 'gpt-5.4-nano',
                        'transcription_seconds' => 120,
                        'soap_input_tokens' => 300,
                        'soap_output_tokens' => 100,
                        'soap_total_tokens' => 400,
                        'estimated_transcription_cost_usd' => 0.006,
                        'estimated_soap_cost_usd' => 0.000675,
                        'estimated_total_cost_usd' => 0.006675,
                    ],
                ],
            ])
            ->assertCreated();

        $response = $this
            ->withToken($token)
            ->get('/api/consultations/costs/export');

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('consultation_id,consulted_at,patient_id,patient_name', $csv);
        $this->assertStringContainsString('gpt-4o-mini-transcribe,gpt-5.4-nano,120,300,100,400,0.006,0.000675,0.006675,USD', $csv);
    }
}
