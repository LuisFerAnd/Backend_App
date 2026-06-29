<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\OpenAIClinicalAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class AiConsultationController extends Controller
{
    public function transcribe(Request $request, OpenAIClinicalAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'audio' => ['required', 'file', 'mimes:mp3,mp4,mpeg,mpga,m4a,wav,webm', 'max:25600'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'prompt' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        try {
            $transcription = $assistant->transcribe(
                $data['audio'],
                $data['language'] ?? null,
                $data['prompt'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 500);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'transcript' => $transcription['text'],
            'model' => $transcription['model'],
        ]);
    }

    public function draft(Request $request, OpenAIClinicalAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['sometimes', 'nullable', 'integer', 'exists:patients,id'],
            'audio' => ['sometimes', 'file', 'mimes:mp3,mp4,mpeg,mpga,m4a,wav,webm', 'max:25600'],
            'transcript' => ['required_without:audio', 'nullable', 'string', 'max:60000'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'prompt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'context' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'audio_duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $patient = isset($data['patient_id'])
            ? $this->findDoctorPatient($request, $data['patient_id'])
            : null;

        try {
            $transcription = null;
            $transcript = trim((string) ($data['transcript'] ?? ''));

            if ($request->hasFile('audio')) {
                $transcription = $assistant->transcribe(
                    $data['audio'],
                    $data['language'] ?? null,
                    $data['prompt'] ?? null
                );
                $transcript = $transcription['text'];
            }

            $draft = $assistant->draftConsultation($transcript, $patient, $data['context'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'transcript' => $transcript,
            'draft' => $draft['draft'],
            'models' => [
                'transcription' => $transcription['model'] ?? null,
                'soap_formatter' => $draft['model'],
            ],
            'usage' => $draft['usage'],
            'cost' => $this->estimateCost(
                $draft['usage'],
                $transcription['model'] ?? null,
                $draft['model'],
                $data['audio_duration_seconds'] ?? null
            ),
        ]);
    }

    private function estimateCost(mixed $usage, ?string $transcriptionModel, string $soapModel, ?int $audioDurationSeconds): array
    {
        $usage = is_array($usage) ? $usage : [];
        $inputTokens = (int) data_get($usage, 'input_tokens', 0);
        $outputTokens = (int) data_get($usage, 'output_tokens', 0);
        $totalTokens = (int) data_get($usage, 'total_tokens', $inputTokens + $outputTokens);
        $soapInputCostPer1m = (float) config('services.openai.pricing.soap_input_cost_per_1m', 0);
        $soapOutputCostPer1m = (float) config('services.openai.pricing.soap_output_cost_per_1m', 0);
        $transcriptionCostPerMinute = (float) config('services.openai.pricing.transcription_cost_per_minute', 0);
        $transcriptionMinutes = max((int) ($audioDurationSeconds ?? 0), 0) / 60;
        $soapCost = (($inputTokens * $soapInputCostPer1m) + ($outputTokens * $soapOutputCostPer1m)) / 1_000_000;
        $transcriptionCost = $transcriptionMinutes * $transcriptionCostPerMinute;

        return [
            'currency' => 'USD',
            'transcription_model' => $transcriptionModel,
            'soap_model' => $soapModel,
            'soap_input_tokens' => $inputTokens,
            'soap_output_tokens' => $outputTokens,
            'soap_total_tokens' => $totalTokens,
            'transcription_seconds' => (int) ($audioDurationSeconds ?? 0),
            'soap_input_cost_per_1m' => $soapInputCostPer1m,
            'soap_output_cost_per_1m' => $soapOutputCostPer1m,
            'transcription_cost_per_minute' => $transcriptionCostPerMinute,
            'estimated_soap_cost_usd' => round($soapCost, 8),
            'estimated_transcription_cost_usd' => round($transcriptionCost, 8),
            'estimated_total_cost_usd' => round($soapCost + $transcriptionCost, 8),
        ];
    }

    private function findDoctorPatient(Request $request, int $patientId): Patient
    {
        return Patient::where('doctor_id', $request->user()->id)
            ->where('id', $patientId)
            ->firstOrFail();
    }
}
