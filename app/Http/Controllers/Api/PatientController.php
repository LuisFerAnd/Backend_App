<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $this->normalizeDni($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'dni' => [
                'required',
                'string',
                'max:30',
                Rule::unique('patients', 'dni'),
            ],
        ], $this->validationMessages());

        try {
            $patient = Patient::create([
                ...$data,
                'doctor_id' => $request->user()->id,
            ]);
        } catch (QueryException $exception) {
            $this->throwDniConflict($exception);
        }

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
        $this->normalizeDni($request);

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:120'],
            'last_name' => ['sometimes', 'required', 'string', 'max:120'],
            'dni' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('patients', 'dni')->ignore($patient),
            ],
        ], $this->validationMessages());

        try {
            $patient->update($data);
        } catch (QueryException $exception) {
            $this->throwDniConflict($exception);
        }

        return response()->json([
            'patient' => $patient,
        ]);
    }

    private function ensurePatientBelongsToDoctor(Request $request, Patient $patient): void
    {
        abort_if($patient->doctor_id !== $request->user()->id, 404);
    }

    private function normalizeDni(Request $request): void
    {
        if (! $request->has('dni')) {
            return;
        }

        $dni = strtoupper(trim((string) $request->input('dni')));
        $request->merge(['dni' => preg_replace('/[\s-]+/', '', $dni)]);
    }

    private function validationMessages(): array
    {
        return [
            'dni.unique' => 'Este DNI ya está registrado para otro paciente.',
        ];
    }

    private function throwDniConflict(QueryException $exception): never
    {
        if (in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
            throw ValidationException::withMessages([
                'dni' => 'Este DNI ya está registrado para otro paciente.',
            ]);
        }

        throw $exception;
    }
}
