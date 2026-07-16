<?php

namespace App\Services;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use SPSS\Sav\Reader as SavReader;
use SPSS\Sav\Variable as SavVariable;
use SPSS\Sav\Writer as SavWriter;

class SoapEvaluationExporter
{
    private const NOT_APPLICABLE_SCALE_FIELDS = [
        'soap_subjetivo',
        'soap_objetivo',
        'soap_evaluacion',
        'soap_plan',
        'soap_ubicacion',
        'soap_claridad',
        'err_transcripcion',
        'err_omision',
        'err_agregada',
        'err_confusion',
        'err_ubicacion',
        'err_redaccion',
    ];

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
            'codigo_consulta' => ['Código permanente de consulta', 'string', '', 'Identificación'],
            'fecha_prueba' => ['Fecha de la prueba', 'date', 'YYYY-MM-DD', 'Identificación'],
            'evaluador_nombre' => ['Nombre del evaluador', 'string', '', 'Identificación'],
            'evaluador_especialidad' => ['Especialidad del evaluador', 'string', '', 'Identificación'],
            'consulta_duracion_seg' => ['Duración de consulta', 'integer', 'segundos', 'Identificación'],
            'audio_duracion_seg' => ['Duración del audio', 'integer', 'segundos', 'Identificación'],
            'ia_tiempo_seg' => ['Tiempo exacto del prototipo', 'decimal', 'segundos', 'Tiempo'],
            'tiempo_procesamiento_seg' => ['Tiempo exacto de procesamiento', 'decimal', 'segundos', 'Tiempo'],
            'tiempo_procesamiento_ms' => ['Tiempo exacto de procesamiento', 'integer', 'milisegundos', 'Tiempo'],
            'rango_tiempo_codigo' => ['Rango ordinal del tiempo', 'integer', '1=Muy lento; 2=Lento; 3=Moderado; 4=Rápido; 5=Muy rápido', 'Tiempo'],
            'rango_tiempo_etiqueta' => ['Etiqueta del rango de tiempo', 'string', '', 'Tiempo'],
            'estado_procesamiento' => ['Estado del procesamiento', 'string', 'pending|processing|completed|failed|timeout|cancelled', 'Auditoría'],
            'numero_reintentos' => ['Número de reintentos', 'integer', '', 'Auditoría'],
            'manual_tiempo_seg' => ['Tiempo manual', 'integer', 'segundos', 'Tiempo'],
            'manual_rango_codigo' => ['Rango ordinal del tiempo manual', 'integer', '1=Muy lento; 2=Lento; 3=Moderado; 4=Rápido; 5=Muy rápido', 'Tiempo'],
            'manual_rango_etiqueta' => ['Etiqueta del tiempo manual', 'string', '', 'Tiempo'],
            'diferencia_tiempo_seg' => ['Tiempo manual menos prototipo', 'integer', 'segundos', 'Tiempo'],
            'diferencia_tiempo_exacta_seg' => ['Diferencia exacta: manual menos prototipo', 'decimal', 'segundos', 'Tiempo'],
            'ahorro_tiempo_porcentaje' => ['Ahorro de tiempo respecto al método manual', 'decimal', 'porcentaje; positivo=ahorro, negativo=pérdida', 'Tiempo'],
            'ahorro_tiempo_codigo' => ['Clasificación ordinal del ahorro de tiempo', 'integer', '1=Pérdida considerable; 2=Pérdida leve; 3=Sin cambio relevante; 4=Ahorro moderado; 5=Ahorro considerable', 'Tiempo'],
            'ahorro_tiempo_etiqueta' => ['Etiqueta de la clasificación del ahorro', 'string', '', 'Tiempo'],
            'estado_evaluacion' => ['Estado de evaluación', 'string', 'pending|draft|completed', 'Auditoría'],
            'estado_general' => ['Resultado técnico general', 'string', '', 'Auditoría'],
            'etapa_fallo' => ['Etapa del fallo', 'string', '', 'Auditoría'],
            'codigo_error' => ['Código técnico del fallo', 'string', '', 'Auditoría'],
            'tipo_resultado_evaluacion' => ['Tipo de resultado evaluado', 'string', '', 'Auditoría'],
            'segmentos_creados' => ['Cantidad de segmentos', 'integer', '', 'Auditoría'],
            'segmentos_enviados' => ['Segmentos recibidos', 'integer', '', 'Auditoría'],
            'segmentos_transcritos' => ['Segmentos transcritos', 'integer', '', 'Auditoría'],
            'soap_generado' => ['SOAP generado', 'integer', '0=No; 1=Sí', 'Auditoría'],
            'pdf_generado' => ['PDF generado', 'integer', '0=No; 1=Sí', 'Auditoría'],
            'intento_procesamiento' => ['Intento evaluado', 'integer', '', 'Auditoría'],
            'uso_prototipo' => ['Uso del prototipo', 'integer', '0=No; 1=Sí', 'I'],
            'transcripcion_audio' => ['Transcripción del audio', 'integer', '0=No; 1=Sí', 'I'],
            'procesamiento_clinico' => ['Procesamiento clínico', 'integer', '0=No; 1=Sí', 'I'],
            'generacion_soap' => ['Generación SOAP', 'integer', '0=No; 1=Sí', 'I'],
        ];
        foreach (['soap_subjetivo', 'soap_objetivo', 'soap_evaluacion', 'soap_plan', 'soap_ubicacion', 'soap_claridad'] as $key) {
            $variables[$key] = [ucwords(str_replace('_', ' ', $key)), 'integer', '1=No cumple; 2=Parcial; 3=Cumple; 98 en SAV o vacío en CSV/XLSX=No aplica', 'III'];
        }
        $variables += ['soap_total' => ['Puntaje SOAP', 'integer', '6-18', 'III'], 'soap_porcentaje' => ['Porcentaje SOAP', 'decimal', 'porcentaje normalizado (6=0%; 18=100%)', 'III']];
        foreach (['err_transcripcion', 'err_omision', 'err_agregada', 'err_confusion', 'err_ubicacion', 'err_redaccion'] as $key) {
            $variables[$key] = [ucwords(str_replace('_', ' ', $key)), 'integer', '1=Totalmente erróneo; 2=Grave; 3=Moderado; 4=Leve; 5=No presenta; 98 en SAV o vacío en CSV/XLSX=No aplica', 'IV'];
        }
        $variables += ['err_total' => ['Puntaje total de la escala de errores', 'integer', '6-30; mayor es mejor', 'IV'], 'err_totalmente_erroneos' => ['Criterios totalmente erróneos', 'integer', '', 'IV'], 'err_graves' => ['Errores graves', 'integer', '', 'IV'], 'err_moderados' => ['Errores moderados', 'integer', '', 'IV'], 'err_leves' => ['Errores leves', 'integer', '', 'IV'], 'err_sin_error' => ['Criterios sin error', 'integer', '', 'IV'], 'err_observaciones' => ['Observaciones de errores', 'string', '', 'IV']];
        foreach (range(1, 6) as $i) {
            $variables["up$i"] = ["Utilidad percibida $i", 'integer', '1-5 Likert', 'V'];
        }
        $variables += ['up_total' => ['Total utilidad', 'integer', '', 'V'], 'up_promedio' => ['Promedio utilidad', 'decimal', '', 'V']];
        foreach (range(1, 6) as $i) {
            $variables["fu$i"] = ["Facilidad de uso $i", 'integer', '1-5 Likert', 'V'];
        }
        $variables += ['fu_total' => ['Total facilidad', 'integer', '', 'V'], 'fu_promedio' => ['Promedio facilidad', 'decimal', '', 'V']];

        return $variables;
    }

    private function row($e): array
    {
        $row = [
            'codigo_prueba' => $e->test_code, 'codigo_consulta' => $e->consultation?->consultation_code, 'fecha_prueba' => $e->test_date?->format('Y-m-d'), 'evaluador_nombre' => $e->evaluator_name,
            'evaluador_especialidad' => $e->evaluator_specialization, 'consulta_duracion_seg' => $e->consultation_duration_seconds,
            'audio_duracion_seg' => $this->audioDurationSeconds($e),
            'ia_tiempo_seg' => $e->consultation?->processing_time_seconds ?? $e->ai_time_seconds,
            'tiempo_procesamiento_seg' => $e->consultation?->processing_time_seconds,
            'tiempo_procesamiento_ms' => $e->consultation?->processing_time_ms,
            'rango_tiempo_codigo' => $e->consultation?->processing_time_range,
            'rango_tiempo_etiqueta' => $e->consultation?->processing_time_label,
            'estado_procesamiento' => $e->consultation?->processing_status,
            'numero_reintentos' => $e->consultation?->retry_count,
            'manual_tiempo_seg' => $e->manual_time_seconds,
            'manual_rango_codigo' => $e->manual_time_range,
            'manual_rango_etiqueta' => $e->manual_time_label,
            'diferencia_tiempo_seg' => $e->time_difference_seconds,
            'diferencia_tiempo_exacta_seg' => $e->time_difference_seconds_exact,
            'ahorro_tiempo_porcentaje' => $e->time_savings_percentage,
            'ahorro_tiempo_codigo' => $e->time_savings_range,
            'ahorro_tiempo_etiqueta' => $e->time_savings_label,
            'estado_evaluacion' => $e->status,
            'estado_general' => $e->consultation?->overall_status, 'etapa_fallo' => $e->consultation?->failure_stage,
            'codigo_error' => $e->consultation?->failure_code, 'tipo_resultado_evaluacion' => $e->evaluation_result_type,
            'segmentos_creados' => $e->consultation?->expected_segments, 'segmentos_enviados' => $e->consultation?->received_segments,
            'segmentos_transcritos' => $e->consultation?->transcribed_segments, 'soap_generado' => $e->consultation?->soap_status === 'completed' ? 1 : 0,
            'pdf_generado' => $e->consultation?->pdf_status === 'completed' ? 1 : 0,
            'intento_procesamiento' => $e->processingAttempt?->attempt_number,
            'uso_prototipo' => $e->use_prototype, 'transcripcion_audio' => $e->audio_transcription, 'procesamiento_clinico' => $e->clinical_processing, 'generacion_soap' => $e->soap_generation,
            'soap_subjetivo' => $e->soap_subjective, 'soap_objetivo' => $e->soap_objective, 'soap_evaluacion' => $e->soap_assessment, 'soap_plan' => $e->soap_plan, 'soap_ubicacion' => $e->soap_placement, 'soap_claridad' => $e->soap_clarity, 'soap_total' => $e->soap_total, 'soap_porcentaje' => $e->soap_percentage,
            'err_transcripcion' => $e->error_transcription, 'err_omision' => $e->error_omission, 'err_agregada' => $e->error_added, 'err_confusion' => $e->error_confusion, 'err_ubicacion' => $e->error_placement, 'err_redaccion' => $e->error_wording, 'err_total' => $e->error_total, 'err_totalmente_erroneos' => $e->error_totally_wrong_count, 'err_graves' => $e->error_severe_count, 'err_moderados' => $e->error_moderate_count, 'err_leves' => $e->error_mild_count, 'err_sin_error' => $e->error_none_count, 'err_observaciones' => $e->error_observations,
        ];
        foreach (range(1, 6) as $i) {
            $row["up$i"] = $e->{"utility_$i"};
        }
        $row['up_total'] = $e->utility_total;
        $row['up_promedio'] = $e->utility_average;
        foreach (range(1, 6) as $i) {
            $row["fu$i"] = $e->{"ease_$i"};
        }
        $row += ['fu_total' => $e->ease_total, 'fu_promedio' => $e->ease_average];

        return $row;
    }

    private function csv(Collection $rows): string
    {
        $rows = $this->rowsWithoutNotApplicableScores($rows);
        $path = $this->temp('csv');
        $file = fopen($path, 'wb');
        fwrite($file, "\xEF\xBB\xBF");
        fputcsv($file, array_keys($this->variables()));
        foreach ($rows as $row) {
            fputcsv($file, $row);
        }
        fclose($file);

        return $path;
    }

    private function audioDurationSeconds($evaluation): ?int
    {
        $evaluationDuration = (int) ($evaluation->audio_duration_seconds ?? 0);
        if ($evaluationDuration > 0) {
            return $evaluationDuration;
        }

        $consultation = $evaluation->consultation;
        if (! $consultation) {
            return null;
        }
        $consultationDuration = (int) ($consultation->recording_duration_seconds ?? 0);
        if ($consultationDuration > 0) {
            return $consultationDuration;
        }

        $segmentsDuration = (int) $consultation->audioSegments()->sum('duration_seconds');

        return $segmentsDuration > 0 ? $segmentsDuration : null;
    }

    private function xlsx(Collection $rows): string
    {
        $rows = $this->rowsWithoutNotApplicableScores($rows);
        $spreadsheet = new Spreadsheet;
        $data = $spreadsheet->getActiveSheet();
        $data->setTitle('Datos');
        $data->fromArray([array_keys($this->variables())], null, 'A1');
        if ($rows->isNotEmpty()) {
            $data->fromArray(
                $rows->map(fn ($r) => array_values($r))->all(),
                null,
                'A2',
                true
            );
        }
        $dictionary = $spreadsheet->createSheet();
        $dictionary->setTitle('Diccionario');
        $dictionary->fromArray([['variable', 'etiqueta', 'tipo', 'valores_unidad', 'seccion']], null, 'A1');
        $line = 2;
        foreach ($this->variables() as $name => $meta) {
            $dictionary->fromArray([[$name, ...$meta]], null, 'A'.$line++);
        }
        $path = $this->temp('xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function sav(Collection $rows): string
    {
        $path = $this->temp('sav');
        $definitions = $this->variables();
        $variables = [];

        foreach ($definitions as $name => $metadata) {
            $values = $rows->pluck($name)->all();
            $isNumeric = in_array($metadata[1], ['integer', 'decimal'], true);
            $variable = [
                'name' => $name,
                'label' => $metadata[0],
                'width' => $isNumeric ? 8 : $this->stringWidth($values),
                'decimals' => in_array($name, ['ia_tiempo_seg', 'tiempo_procesamiento_seg', 'diferencia_tiempo_exacta_seg', 'ahorro_tiempo_porcentaje'], true) ? 3 : ($metadata[1] === 'decimal' ? 2 : 0),
                'format' => $isNumeric ? SavVariable::FORMAT_TYPE_F : SavVariable::FORMAT_TYPE_A,
                'columns' => $isNumeric ? 12 : min($this->stringWidth($values), 80),
                'alignment' => $isNumeric ? SavVariable::ALIGN_RIGHT : SavVariable::ALIGN_LEFT,
                'measure' => $this->measureFor($name, $isNumeric),
                'values' => $this->valueLabelsFor($name),
                'missing' => $this->missingValuesFor($name),
                'data' => array_map(
                    fn ($value) => $value === null ? '' : ($isNumeric ? (float) $value : (string) $value),
                    $values
                ),
            ];
            $variables[] = $variable;
        }

        try {
            $writer = SavWriter::createInFile([
                'header' => [
                    'prodName' => '@(#) SANARE SOAP EVALUATIONS',
                    'layoutCode' => 2,
                    'compression' => 1,
                    'weightIndex' => 0,
                    'bias' => 100,
                    'creationDate' => now()->format('d M y'),
                    'creationTime' => now()->format('H:i:s'),
                ],
                'variables' => $variables,
            ], $path);
            $writer->close();

            $verified = SavReader::fromFile($path)->read();
            if (count($verified->data) !== $rows->count() || count($verified->variables) !== count($definitions)) {
                throw new RuntimeException('La verificación del archivo SAV no coincide con los datos exportados.');
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw new RuntimeException('No se pudo generar un archivo SAV válido.', previous: $exception);
        }

        return $path;
    }

    private function stringWidth(array $values): int
    {
        $maximum = max(array_map(fn ($value) => strlen((string) ($value ?? '')), $values) ?: [1]);

        return min(max($maximum, 8), 2000);
    }

    private function measureFor(string $name, bool $isNumeric): int
    {
        if (! $isNumeric) {
            return SavVariable::MEASURE_NOMINAL;
        }
        if (in_array($name, ['rango_tiempo_codigo', 'manual_rango_codigo', 'ahorro_tiempo_codigo'], true)) {
            return SavVariable::MEASURE_ORDINAL;
        }
        if (preg_match('/^(uso_|transcripcion_|procesamiento_|generacion_|soap_(subjetivo|objetivo|evaluacion|plan|ubicacion|claridad)|err_|up[1-6]$|fu[1-6]$)/', $name)) {
            return SavVariable::MEASURE_ORDINAL;
        }

        return SavVariable::MEASURE_SCALE;
    }

    private function valueLabelsFor(string $name): array
    {
        if (in_array($name, ['uso_prototipo', 'transcripcion_audio', 'procesamiento_clinico', 'generacion_soap'], true)) {
            return [0 => 'No', 1 => 'Sí'];
        }
        if (in_array($name, ['rango_tiempo_codigo', 'manual_rango_codigo'], true)) {
            return [1 => 'Muy lento', 2 => 'Lento', 3 => 'Moderado', 4 => 'Rápido', 5 => 'Muy rápido'];
        }
        if ($name === 'ahorro_tiempo_codigo') {
            return [1 => 'Pérdida considerable', 2 => 'Pérdida leve', 3 => 'Sin cambio relevante', 4 => 'Ahorro moderado', 5 => 'Ahorro considerable'];
        }
        if (preg_match('/^soap_(subjetivo|objetivo|evaluacion|plan|ubicacion|claridad)$/', $name)) {
            return [1 => 'No cumple', 2 => 'Cumple parcialmente', 3 => 'Cumple', 98 => 'No aplica: no se generó SOAP'];
        }
        if (preg_match('/^err_(transcripcion|omision|agregada|confusion|ubicacion|redaccion)$/', $name)) {
            return [1 => 'Totalmente erróneo', 2 => 'Error grave', 3 => 'Error moderado', 4 => 'Error leve', 5 => 'No presenta', 98 => 'No aplica: no se generó SOAP'];
        }
        if (preg_match('/^(up|fu)[1-6]$/', $name)) {
            return [1 => 'Totalmente en desacuerdo', 2 => 'En desacuerdo', 3 => 'Neutral', 4 => 'De acuerdo', 5 => 'Totalmente de acuerdo'];
        }

        return [];
    }

    private function missingValuesFor(string $name): array
    {
        return in_array($name, self::NOT_APPLICABLE_SCALE_FIELDS, true) ? [98] : [];
    }

    private function rowsWithoutNotApplicableScores(Collection $rows): Collection
    {
        return $rows->map(function (array $row): array {
            foreach (self::NOT_APPLICABLE_SCALE_FIELDS as $field) {
                if ($row[$field] !== null && (int) $row[$field] === 98) {
                    $row[$field] = null;
                }
            }

            return $row;
        });
    }

    private function temp(string $extension): string
    {
        return storage_path('app/private/'.bin2hex(random_bytes(16)).'.'.$extension);
    }
}
