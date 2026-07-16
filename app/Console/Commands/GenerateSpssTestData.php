<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Models\ConsultationProcessingAttempt;
use App\Models\SoapEvaluation;
use App\Services\ProcessingTimeService;
use App\Services\SoapEvaluationCalculator;
use App\Services\SoapEvaluationExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class GenerateSpssTestData extends Command
{
    protected $signature = 'sanare:generate-spss-tests {--output=pruebas_spss : Directorio de salida absoluto o relativo a AppTesis}';

    protected $description = 'Genera diez evaluaciones sintéticas en CSV, XLSX y SAV para validar la importación en SPSS';

    public function handle(
        SoapEvaluationCalculator $calculator,
        ProcessingTimeService $processingTime,
        SoapEvaluationExporter $exporter
    ): int {
        $output = (string) $this->option('output');
        $directory = str_starts_with($output, DIRECTORY_SEPARATOR)
            ? $output
            : base_path('../'.$output);
        File::ensureDirectoryExists($directory);

        $evaluations = collect($this->cases())->map(function (array $case, int $index) use ($calculator, $processingTime): SoapEvaluation {
            $number = $index + 1;
            [$prototypeRange, $prototypeLabel] = $processingTime->classifyProcessingTime($case['prototype']);
            $scores = $this->scores($number);
            $calculated = $calculator->calculate([
                'manual_time_seconds' => $case['manual'],
                'prototype_time_seconds' => $case['prototype'],
                ...$scores,
            ]);

            $consultation = new Consultation([
                'consultation_code' => sprintf('CONS-SPSS-%03d', $number),
                'recording_duration_seconds' => 420 + ($number * 30),
                'processing_status' => 'completed',
                'processing_time_ms' => (int) round($case['prototype'] * 1000),
                'processing_time_seconds' => $case['prototype'],
                'processing_time_range' => $prototypeRange,
                'processing_time_label' => $prototypeLabel,
                'retry_count' => $number % 3 === 0 ? 1 : 0,
                'expected_segments' => 2,
                'received_segments' => 2,
                'transcribed_segments' => 2,
                'soap_status' => 'completed',
                'pdf_status' => 'completed',
                'overall_status' => 'completed',
            ]);

            $attempt = new ConsultationProcessingAttempt(['attempt_number' => 1]);
            $evaluation = new SoapEvaluation;
            $evaluation->forceFill([
                'test_code' => sprintf('SPSS-TEST-%03d', $number),
                'test_date' => Carbon::create(2026, 7, $number),
                'evaluator_name' => 'Evaluador sintético '.(($number - 1) % 3 + 1),
                'evaluator_specialization' => ['Medicina interna', 'Medicina familiar', 'Pediatría'][($number - 1) % 3],
                'consultation_duration_seconds' => 600 + ($number * 45),
                'audio_duration_seconds' => 420 + ($number * 30),
                'ai_time_seconds' => (int) round($case['prototype']),
                'status' => 'completed',
                'evaluation_result_type' => 'successful_soap',
                'error_observations' => 'Registro sintético para validar la importación en SPSS.',
                ...$calculated,
            ]);
            $evaluation->setRelation('consultation', $consultation);
            $evaluation->setRelation('processingAttempt', $attempt);

            return $evaluation;
        });

        foreach (['csv', 'xlsx', 'sav'] as $format) {
            $temporary = $exporter->export($evaluations, $format);
            $destination = $directory.'/pruebas_sanare_10.'.$format;
            File::copy($temporary, $destination);
            File::delete($temporary);
            $this->line($destination);
        }

        File::put($directory.'/LEEME.txt', $this->readme());
        $this->createZip($directory);
        $this->info('Se generaron 10 registros sintéticos en tres formatos.');

        return self::SUCCESS;
    }

    /** @return list<array{manual: int, prototype: int}> */
    private function cases(): array
    {
        return [
            ['manual' => 100, 'prototype' => 140], // -40 %, categoría 1
            ['manual' => 200, 'prototype' => 250], // -25 %, categoría 2
            ['manual' => 300, 'prototype' => 330], // -10 %, categoría 2
            ['manual' => 240, 'prototype' => 252], //  -5 %, categoría 3
            ['manual' => 180, 'prototype' => 180], //   0 %, categoría 3
            ['manual' => 200, 'prototype' => 190], //   5 %, categoría 3
            ['manual' => 240, 'prototype' => 204], //  15 %, categoría 4
            ['manual' => 200, 'prototype' => 150], //  25 %, categoría 4
            ['manual' => 300, 'prototype' => 180], //  40 %, categoría 5
            ['manual' => 150, 'prototype' => 45],  //  70 %, categoría 5
        ];
    }

    /** @return array<string, int> */
    private function scores(int $number): array
    {
        $scores = [
            'use_prototype' => 1,
            'audio_transcription' => 1,
            'clinical_processing' => 1,
            'soap_generation' => 1,
        ];
        foreach (SoapEvaluationCalculator::SOAP as $index => $field) {
            $scores[$field] = 1 + (($number + $index) % 3);
        }
        foreach (SoapEvaluationCalculator::ERRORS as $index => $field) {
            $scores[$field] = 1 + (($number + $index) % 5);
        }
        foreach (SoapEvaluationCalculator::UTILITY as $index => $field) {
            $scores[$field] = 1 + (($number + $index + 1) % 5);
        }
        foreach (SoapEvaluationCalculator::EASE as $index => $field) {
            $scores[$field] = 1 + (($number + $index + 2) % 5);
        }

        return $scores;
    }

    private function createZip(string $directory): void
    {
        $archivePath = $directory.'/pruebas_sanare_10_spss.zip';
        $archive = new ZipArchive;
        if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP de pruebas.');
        }
        foreach (['pruebas_sanare_10.csv', 'pruebas_sanare_10.xlsx', 'pruebas_sanare_10.sav', 'LEEME.txt'] as $file) {
            $archive->addFile($directory.'/'.$file, $file);
        }
        $archive->close();
        $this->line($archivePath);
    }

    private function readme(): string
    {
        return <<<'TEXT'
DATOS SINTÉTICOS SANARE PARA SPSS

Estos archivos contienen 10 registros ficticios. No incluyen datos de pacientes ni resultados reales.

Archivos:
- pruebas_sanare_10.sav: abrir directamente con IBM SPSS Statistics.
- pruebas_sanare_10.xlsx: incluye las hojas Datos y Diccionario.
- pruebas_sanare_10.csv: alternativa de importación en UTF-8.

Casos incluidos para ahorro_tiempo_porcentaje:
-40, -25, -10, -5, 0, 5, 15, 25, 40 y 70 por ciento.

Medición en SPSS:
- ahorro_tiempo_porcentaje: Scale.
- ahorro_tiempo_codigo: Ordinal, con etiquetas de valores 1 a 5.
- ahorro_tiempo_etiqueta: Nominal.

Los rangos son criterios operativos del estudio y no una escala internacional validada.
TEXT;
    }
}
