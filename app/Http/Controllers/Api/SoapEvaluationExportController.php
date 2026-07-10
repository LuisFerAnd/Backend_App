<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SoapEvaluationExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SoapEvaluationExportController extends Controller
{
    public function __invoke(Request $request, string $format, SoapEvaluationController $controller, SoapEvaluationExporter $exporter): BinaryFileResponse
    {
        abort_unless($request->user()->can('evaluations.export'), 403);
        $filters = $request->validate($controller->filterRules());
        $evaluations = $controller->filteredQuery($filters)->get();
        $path = $exporter->export($evaluations, $format);

        \DB::table('soap_evaluation_exports')->insert([
            'user_id' => $request->user()->id,
            'format' => $format,
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE),
            'record_count' => $evaluations->count(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $name = 'evaluaciones_soap_'.now()->timezone(config('app.timezone'))->format('Y-m-d_His').'.'.$format;
        return response()->download($path, $name)->deleteFileAfterSend(true);
    }
}
