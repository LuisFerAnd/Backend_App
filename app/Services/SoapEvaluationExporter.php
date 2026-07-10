<?php

namespace App\Services;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class SoapEvaluationExporter
{
    public function export(Collection $evaluations, string $format): string
    {
        $rows = $evaluations->map(fn ($evaluation) => $this->row($evaluation))->values();
        return match ($format) {
            'csv' => $this->csv($rows),
            'xlsx' => $this->xlsx($rows),
            'sav' => $this->sav($rows),
            default => throw new RuntimeException('Formato no soportado.'),
        };
    }

    public function variables(): array
    {
        $variables = [
            'codigo_prueba' => ['Código único de prueba', 'string', '', 'Identificación'],
            'fecha_prueba' => ['Fecha de la prueba', 'date', 'YYYY-MM-DD', 'Identificación'],
            'evaluador_nombre' => ['Nombre del evaluador', 'string', '', 'Identificación'],
            'evaluador_especialidad' => ['Especialidad del evaluador', 'string', '', 'Identificación'],
            'consulta_duracion_seg' => ['Duración de consulta', 'integer', 'segundos', 'Identificación'],
            'audio_duracion_seg' => ['Duración del audio', 'integer', 'segundos', 'Identificación'],
            'ia_tiempo_seg' => ['Tiempo del prototipo', 'integer', 'segundos', 'Tiempo'],
            'manual_tiempo_seg' => ['Tiempo manual', 'integer', 'segundos', 'Tiempo'],
            'diferencia_tiempo_seg' => ['Tiempo manual menos prototipo', 'integer', 'segundos', 'Tiempo'],
            'estado_evaluacion' => ['Estado de evaluación', 'string', 'pending|draft|completed', 'Auditoría'],
            'uso_prototipo' => ['Uso del prototipo', 'integer', '0=No; 1=Sí', 'I'],
            'transcripcion_audio' => ['Transcripción del audio', 'integer', '0=No; 1=Sí', 'I'],
            'procesamiento_clinico' => ['Procesamiento clínico', 'integer', '0=No; 1=Sí', 'I'],
            'generacion_soap' => ['Generación SOAP', 'integer', '0=No; 1=Sí', 'I'],
        ];
        foreach (['soap_subjetivo', 'soap_objetivo', 'soap_evaluacion', 'soap_plan', 'soap_ubicacion', 'soap_claridad'] as $key) $variables[$key] = [ucwords(str_replace('_', ' ', $key)), 'integer', '0=No cumple; 1=Parcial; 2=Cumple', 'III'];
        $variables += ['soap_total' => ['Puntaje SOAP', 'integer', '0-12', 'III'], 'soap_porcentaje' => ['Porcentaje SOAP', 'decimal', 'porcentaje', 'III']];
        foreach (['err_transcripcion', 'err_omision', 'err_agregada', 'err_confusion', 'err_ubicacion', 'err_redaccion'] as $key) $variables[$key] = [ucwords(str_replace('_', ' ', $key)), 'integer', '0=Ninguno; 1=Leve; 2=Moderado; 3=Grave', 'IV'];
        $variables += ['err_total' => ['Puntaje total de errores', 'integer', '', 'IV'], 'err_sin_error' => ['Criterios sin error', 'integer', '', 'IV'], 'err_leves' => ['Errores leves', 'integer', '', 'IV'], 'err_moderados' => ['Errores moderados', 'integer', '', 'IV'], 'err_graves' => ['Errores graves', 'integer', '', 'IV'], 'err_observaciones' => ['Observaciones de errores', 'string', '', 'IV']];
        foreach (range(1, 6) as $i) $variables["up$i"] = ["Utilidad percibida $i", 'integer', '1-5 Likert', 'V'];
        $variables += ['up_total' => ['Total utilidad', 'integer', '', 'V'], 'up_promedio' => ['Promedio utilidad', 'decimal', '', 'V']];
        foreach (range(1, 6) as $i) $variables["fu$i"] = ["Facilidad de uso $i", 'integer', '1-5 Likert', 'V'];
        $variables += ['fu_total' => ['Total facilidad', 'integer', '', 'V'], 'fu_promedio' => ['Promedio facilidad', 'decimal', '', 'V'], 'creado_en' => ['Fecha de creación', 'datetime', 'ISO 8601', 'Auditoría'], 'actualizado_en' => ['Último guardado', 'datetime', 'ISO 8601', 'Auditoría'], 'completado_en' => ['Fecha de finalización', 'datetime', 'ISO 8601', 'Auditoría']];
        return $variables;
    }

    private function row($e): array
    {
        $row = [
            'codigo_prueba' => $e->test_code, 'fecha_prueba' => $e->test_date?->format('Y-m-d'), 'evaluador_nombre' => $e->evaluator_name,
            'evaluador_especialidad' => $e->evaluator_specialization, 'consulta_duracion_seg' => $e->consultation_duration_seconds,
            'audio_duracion_seg' => $e->audio_duration_seconds, 'ia_tiempo_seg' => $e->ai_time_seconds, 'manual_tiempo_seg' => $e->manual_time_seconds,
            'diferencia_tiempo_seg' => $e->time_difference_seconds, 'estado_evaluacion' => $e->status,
            'uso_prototipo' => $e->use_prototype, 'transcripcion_audio' => $e->audio_transcription, 'procesamiento_clinico' => $e->clinical_processing, 'generacion_soap' => $e->soap_generation,
            'soap_subjetivo' => $e->soap_subjective, 'soap_objetivo' => $e->soap_objective, 'soap_evaluacion' => $e->soap_assessment, 'soap_plan' => $e->soap_plan, 'soap_ubicacion' => $e->soap_placement, 'soap_claridad' => $e->soap_clarity, 'soap_total' => $e->soap_total, 'soap_porcentaje' => $e->soap_percentage,
            'err_transcripcion' => $e->error_transcription, 'err_omision' => $e->error_omission, 'err_agregada' => $e->error_added, 'err_confusion' => $e->error_confusion, 'err_ubicacion' => $e->error_placement, 'err_redaccion' => $e->error_wording, 'err_total' => $e->error_total, 'err_sin_error' => $e->error_none_count, 'err_leves' => $e->error_mild_count, 'err_moderados' => $e->error_moderate_count, 'err_graves' => $e->error_severe_count, 'err_observaciones' => $e->error_observations,
        ];
        foreach (range(1, 6) as $i) $row["up$i"] = $e->{"utility_$i"};
        $row['up_total'] = $e->utility_total; $row['up_promedio'] = $e->utility_average;
        foreach (range(1, 6) as $i) $row["fu$i"] = $e->{"ease_$i"};
        $row += ['fu_total' => $e->ease_total, 'fu_promedio' => $e->ease_average, 'creado_en' => $e->created_at?->toIso8601String(), 'actualizado_en' => $e->last_saved_at?->toIso8601String(), 'completado_en' => $e->completed_at?->toIso8601String()];
        return $row;
    }

    private function csv(Collection $rows): string
    {
        $path = $this->temp('csv'); $file = fopen($path, 'wb'); fwrite($file, "\xEF\xBB\xBF"); fputcsv($file, array_keys($this->variables()));
        foreach ($rows as $row) fputcsv($file, $row);
        fclose($file); return $path;
    }

    private function xlsx(Collection $rows): string
    {
        $spreadsheet = new Spreadsheet(); $data = $spreadsheet->getActiveSheet(); $data->setTitle('Datos');
        $data->fromArray([array_keys($this->variables())], null, 'A1');
        if ($rows->isNotEmpty()) $data->fromArray($rows->map(fn ($r) => array_values($r))->all(), null, 'A2');
        $dictionary = $spreadsheet->createSheet(); $dictionary->setTitle('Diccionario');
        $dictionary->fromArray([['variable', 'etiqueta', 'tipo', 'valores_unidad', 'seccion']], null, 'A1');
        $line = 2; foreach ($this->variables() as $name => $meta) $dictionary->fromArray([[$name, ...$meta]], null, 'A'.$line++);
        $path = $this->temp('xlsx'); (new Xlsx($spreadsheet))->save($path); $spreadsheet->disconnectWorksheets(); return $path;
    }

    private function sav(Collection $rows): string
    {
        $input = $this->temp('json'); $output = $this->temp('sav');
        file_put_contents($input, json_encode(['rows' => $rows, 'variables' => $this->variables()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $script = base_path('scripts/export_sav.py');
        $command = escapeshellarg(config('services.spss.python', 'python3')).' '.escapeshellarg($script).' '.escapeshellarg($input).' '.escapeshellarg($output).' 2>&1';
        exec($command, $lines, $status); @unlink($input);
        if ($status !== 0 || ! is_file($output) || filesize($output) === 0) { @unlink($output); throw new RuntimeException('No se pudo generar SAV: '.implode("\n", $lines)); }
        return $output;
    }

    private function temp(string $extension): string
    {
        return storage_path('app/private/'.bin2hex(random_bytes(16)).'.'.$extension);
    }
}
