<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsultationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'patient_id' => ['sometimes', 'integer', 'exists:patients,id'],
        ]);

        if (isset($filters['patient_id'])) {
            $this->findDoctorPatient($request, $filters['patient_id']);
        }

        $consultations = Consultation::with('patient')
            ->where('doctor_id', $request->user()->id)
            ->when(
                isset($filters['patient_id']),
                fn ($query) => $query->where('patient_id', $filters['patient_id'])
            )
            ->latest('consulted_at')
            ->get();

        return response()->json([
            'consultations' => $consultations,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $patient = $this->findDoctorPatient($request, $data['patient_id']);

        $consultation = Consultation::create([
            ...$data,
            'doctor_id' => $request->user()->id,
            'patient_id' => $patient->id,
            'consulted_at' => $data['consulted_at'] ?? now(),
        ]);

        return response()->json([
            'consultation' => $consultation->load('patient'),
        ], 201);
    }

    public function show(Request $request, Consultation $consultation): JsonResponse
    {
        $this->ensureConsultationBelongsToDoctor($request, $consultation);

        return response()->json([
            'consultation' => $consultation->load('patient'),
        ]);
    }

    public function update(Request $request, Consultation $consultation): JsonResponse
    {
        $this->ensureConsultationBelongsToDoctor($request, $consultation);

        $data = $request->validate($this->rules(required: false));

        if (isset($data['patient_id'])) {
            $patient = $this->findDoctorPatient($request, $data['patient_id']);
            $data['patient_id'] = $patient->id;
        }

        $consultation->update($data);

        return response()->json([
            'consultation' => $consultation->load('patient'),
        ]);
    }

    private function rules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'patient_id' => [$presence, 'integer', 'exists:patients,id'],
            'consulted_at' => ['sometimes', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'subjective' => [$presence, 'string'],
            'objective' => [$presence, 'string'],
            'assessment' => [$presence, 'string'],
            'plan' => [$presence, 'string'],
            'vital_signs' => ['nullable', 'array'],
        ];
    }

    private function findDoctorPatient(Request $request, int $patientId): Patient
    {
        return Patient::where('doctor_id', $request->user()->id)
            ->where('id', $patientId)
            ->firstOrFail();
    }

    private function ensureConsultationBelongsToDoctor(Request $request, Consultation $consultation): void
    {
        abort_if($consultation->doctor_id !== $request->user()->id, 404);
    }
}
