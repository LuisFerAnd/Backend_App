<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function summary(): JsonResponse
    {
        return response()->json([
            'summary' => [
                'doctors' => User::role('doctor')->count(),
                'admins' => User::role('admin')->count(),
                'patients' => Patient::count(),
                'consultations' => Consultation::count(),
            ],
        ]);
    }

    public function doctors(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);

        $doctors = User::with('roles:id,name')
            ->withCount(['patients', 'consultations'])
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'doctors' => $doctors,
        ]);
    }

    public function patients(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);

        $patients = Patient::with('doctor:id,name,email')
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'patients' => $patients,
        ]);
    }

    public function consultations(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);
        $status = $request->validate([
            'overall_status' => ['nullable', 'string', 'in:created,recording,recording_completed,uploading,transcribing,generating_soap,completed,completed_with_warnings,failed,timeout,cancelled,pending_sync'],
            'failure_stage' => ['nullable', 'string', 'max:40'],
        ]);

        $consultations = Consultation::with([
            'doctor:id,name,email',
            'patient:id,doctor_id,first_name,last_name,dni',
            'soapEvaluation:id,consultation_id,status,test_code',
        ])
            ->when($status['overall_status'] ?? null, fn ($query, $value) => $query->where('overall_status', $value))
            ->when($status['failure_stage'] ?? null, fn ($query, $value) => $query->where('failure_stage', $value))
            ->latest('consulted_at')
            ->paginate($perPage);

        return response()->json([
            'consultations' => $consultations,
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->integer('per_page', 25), 1), 100);
    }
}
