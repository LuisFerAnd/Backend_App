<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $patients = Patient::where('doctor_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'patients' => $patients,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'dni' => [
                'required',
                'string',
                'max:30',
                Rule::unique('patients', 'dni')
                    ->where(fn ($query) => $query->where('doctor_id', $request->user()->id)),
            ],
        ]);

        $patient = Patient::create([
            ...$data,
            'doctor_id' => $request->user()->id,
        ]);

        return response()->json([
            'patient' => $patient,
        ], 201);
    }

    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->ensurePatientBelongsToDoctor($request, $patient);

        return response()->json([
            'patient' => $patient->load('consultations'),
        ]);
    }

    public function update(Request $request, Patient $patient): JsonResponse
    {
        $this->ensurePatientBelongsToDoctor($request, $patient);

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:120'],
            'last_name' => ['sometimes', 'required', 'string', 'max:120'],
            'dni' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('patients', 'dni')
                    ->where(fn ($query) => $query->where('doctor_id', $request->user()->id))
                    ->ignore($patient),
            ],
        ]);

        $patient->update($data);

        return response()->json([
            'patient' => $patient,
        ]);
    }

    private function ensurePatientBelongsToDoctor(Request $request, Patient $patient): void
    {
        abort_if($patient->doctor_id !== $request->user()->id, 404);
    }
}
