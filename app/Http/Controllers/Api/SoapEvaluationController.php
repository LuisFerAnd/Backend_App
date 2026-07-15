<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\SoapEvaluation;
use App\Services\SoapEvaluationCalculator;
use App\Services\SoapEvaluationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SoapEvaluationController extends Controller
{
    public function forConsultation(Request $request, Consultation $consultation, SoapEvaluationFactory $factory): JsonResponse
    {
        $this->authorizeConsultation($request, $consultation);
        if ($request->user()->hasRole('admin')) {
            abort_unless($request->user()->can('evaluations.view_all'), 403);
            $evaluation = SoapEvaluation::where('consultation_id', $consultation->id)->first();
            abort_if($evaluation === null, 404, 'Esta consulta todavía no tiene una evaluación registrada por el doctor.');
        } else {
            abort_unless($request->user()->can('evaluations.view_own'), 403);
            $evaluation = $factory->firstOrCreate($consultation, $request->user());
        }

        return response()->json(['evaluation' => $this->load($evaluation)]);
    }

    public function update(Request $request, SoapEvaluation $evaluation, SoapEvaluationCalculator $calculator): JsonResponse
    {
        abort_if($request->user()->hasRole('admin'), 403, 'El administrador solo puede consultar evaluaciones.');
        $this->authorizeEvaluation($request, $evaluation, 'evaluations.update_own');
        if ($evaluation->status === 'completed') {
            return response()->json(['message' => 'La evaluación completada no puede editarse.'], 409);
        }

        $data = $request->validate($this->rules());
        $expectedVersion = (int) $data['version'];
        unset($data['version']);
        $data = $this->writableData(
            $calculator->calculate([...$evaluation->toArray(), ...$data])
        );
        $data['status'] = 'draft';
        $data['updated_by'] = $request->user()->id;
        $data['last_saved_at'] = now();
        $data['version'] = $expectedVersion + 1;

        $updated = SoapEvaluation::whereKey($evaluation->id)->where('version', $expectedVersion)->update($data);
        if (! $updated) {
            return response()->json([
                'message' => 'La evaluación fue modificada en otro dispositivo.',
                'evaluation' => $this->load($evaluation->fresh()),
            ], 409);
        }

        return response()->json(['evaluation' => $this->load($evaluation->fresh())]);
    }

    public function complete(Request $request, SoapEvaluation $evaluation, SoapEvaluationCalculator $calculator): JsonResponse
    {
        abort_if($request->user()->hasRole('admin'), 403, 'El administrador solo puede consultar evaluaciones.');
        $this->authorizeEvaluation($request, $evaluation, 'evaluations.update_own');
        if ($evaluation->status === 'completed') {
            return response()->json(['evaluation' => $this->load($evaluation)]);
        }

        $data = $request->validate($this->rules());
        $evaluation->loadMissing('consultation');
        $soapGenerated = $evaluation->consultation?->soap_status === 'completed';
        if (! $soapGenerated) {
            foreach (SoapEvaluationCalculator::SOAP as $field) {
                $data[$field] = 98;
            }
            foreach (SoapEvaluationCalculator::ERRORS as $field) {
                $data[$field] = 98;
            }
            $data['evaluation_result_type'] ??= $evaluation->consultation?->overall_status === 'cancelled' ? 'cancelled_by_user' : 'technical_failure';
        } else {
            $hasSoapDeficiencies = collect(SoapEvaluationCalculator::SOAP)
                ->contains(fn (string $field) => isset($data[$field]) && (int) $data[$field] < 3);
            $data['evaluation_result_type'] ??= $hasSoapDeficiencies ? 'soap_with_errors' : 'successful_soap';
        }
        $merged = $calculator->calculate([...$evaluation->toArray(), ...$data]);
        $missing = collect($calculator->requiredFields($soapGenerated))->filter(fn (string $key) => ! array_key_exists($key, $merged) || $merged[$key] === null)->values();
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['required_fields' => ['Faltan respuestas obligatorias: '.$missing->join(', ')]]);
        }

        $expectedVersion = (int) $data['version'];
        $merged = $this->writableData($merged);
        $merged['status'] = 'completed';
        $merged['completed_at'] = now();
        $merged['last_saved_at'] = now();
        $merged['updated_by'] = $request->user()->id;
        $merged['version'] = $expectedVersion + 1;

        $updated = SoapEvaluation::whereKey($evaluation->id)->where('version', $expectedVersion)->update($merged);
        if (! $updated) {
            return response()->json(['message' => 'Conflicto de versión.'], 409);
        }

        $evaluation->consultation()->update(['evaluation_status' => 'completed']);

        return response()->json(['evaluation' => $this->load($evaluation->fresh())]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('evaluations.view_all'), 403);
        $filters = $request->validate($this->filterRules());
        $query = $this->filteredQuery($filters);
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);

        return response()->json(['evaluations' => $query->paginate($perPage)]);
    }

    public function show(Request $request, SoapEvaluation $evaluation): JsonResponse
    {
        $this->authorizeEvaluation($request, $evaluation, 'evaluations.view_own');

        return response()->json(['evaluation' => $this->load($evaluation)]);
    }

    public function filteredQuery(array $filters)
    {
        return SoapEvaluation::query()->with(['consultation:id,consultation_code,consulted_at,recording_duration_seconds,recording_status,upload_status,transcription_status,soap_status,pdf_status,overall_status,failure_stage,failure_code,user_friendly_error_message,expected_segments,received_segments,transcribed_segments', 'processingAttempt', 'evaluator:id,name,specialization'])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($inner) => $inner->where('test_code', 'like', "%$v%")->orWhere('evaluator_name', 'like', "%$v%")->orWhere('evaluator_specialization', 'like', "%$v%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['overall_status'] ?? null, fn ($q, $v) => $q->whereHas('consultation', fn ($c) => $c->where('overall_status', $v)))
            ->when($filters['evaluation_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->when($filters['evaluator_id'] ?? null, fn ($q, $v) => $q->where('evaluator_id', $v))
            ->when($filters['specialization'] ?? null, fn ($q, $v) => $q->where('evaluator_specialization', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('test_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('test_date', '<=', $v))
            ->orderBy($filters['sort'] ?? 'test_date', $filters['direction'] ?? 'desc');
    }

    public function filterRules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['pending', 'draft', 'completed', 'provisional'])], 'overall_status' => ['nullable', Rule::in(['created', 'recording', 'recording_completed', 'uploading', 'transcribing', 'generating_soap', 'completed', 'completed_with_warnings', 'failed', 'cancelled', 'pending_sync'])], 'evaluation_id' => ['nullable', 'integer', 'exists:soap_evaluations,id'], 'evaluator_id' => ['nullable', 'integer'], 'specialization' => ['nullable', 'string', 'max:255'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'sort' => ['nullable', Rule::in(['test_date', 'test_code'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])]];
    }

    private function rules(): array
    {
        $rules = ['version' => ['required', 'integer', 'min:1'], 'consultation_duration_seconds' => ['nullable', 'integer', 'min:0'], 'consultation_duration_source' => ['nullable', Rule::in(['system', 'manual'])], 'manual_time_seconds' => ['nullable', 'integer', 'min:0'], 'error_observations' => ['nullable', 'string', 'max:2000']];
        foreach (SoapEvaluationCalculator::BINARY as $field) {
            $rules[$field] = ['nullable', Rule::in([0, 1])];
        }
        foreach (SoapEvaluationCalculator::SOAP as $field) {
            $rules[$field] = ['nullable', Rule::in([1, 2, 3, 98])];
        }
        foreach (SoapEvaluationCalculator::ERRORS as $field) {
            $rules[$field] = ['nullable', Rule::in([1, 2, 3, 4, 5, 98])];
        }
        foreach ([...SoapEvaluationCalculator::UTILITY, ...SoapEvaluationCalculator::EASE] as $field) {
            $rules[$field] = ['nullable', Rule::in([1, 2, 3, 4, 5])];
        }

        return $rules;
    }

    private function writableData(array $data): array
    {
        $fields = [
            'consultation_duration_seconds',
            'consultation_duration_source',
            'manual_time_seconds',
            'time_difference_seconds',
            'error_observations',
            'evaluation_result_type',
            ...SoapEvaluationCalculator::BINARY,
            ...SoapEvaluationCalculator::SOAP,
            'soap_total',
            'soap_max',
            'soap_percentage',
            ...SoapEvaluationCalculator::ERRORS,
            'error_total',
            'error_totally_wrong_count',
            'error_none_count',
            'error_mild_count',
            'error_moderate_count',
            'error_severe_count',
            ...SoapEvaluationCalculator::UTILITY,
            'utility_total',
            'utility_average',
            ...SoapEvaluationCalculator::EASE,
            'ease_total',
            'ease_average',
        ];

        return array_intersect_key($data, array_flip($fields));
    }

    private function authorizeConsultation(Request $request, Consultation $consultation): void
    {
        abort_if(! $request->user()->hasRole('admin') && $consultation->doctor_id !== $request->user()->id, 404);
    }

    private function authorizeEvaluation(Request $request, SoapEvaluation $evaluation, string $permission): void
    {
        if ($request->user()->can('evaluations.view_all')) {
            return;
        }
        abort_unless($request->user()->can($permission) && $evaluation->evaluator_id === $request->user()->id, 403);
    }

    private function load(SoapEvaluation $evaluation): SoapEvaluation
    {
        return $evaluation->load(['consultation:id,consultation_code,consulted_at,recording_duration_seconds,recording_status,upload_status,transcription_status,soap_status,pdf_status,overall_status,failure_stage,failure_code,user_friendly_error_message,expected_segments,received_segments,transcribed_segments', 'processingAttempt', 'evaluator:id,name,specialization']);
    }
}
