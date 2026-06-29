<?php

namespace App\Services;

use App\Models\Patient;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class OpenAIClinicalAssistant
{
    private const UNSPECIFIED = 'no especificado';

    private string $baseUrl;

    private string $transcriptionModel;

    private string $transcriptionLanguage;

    private string $soapModel;

    private string $soapEffort;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.openai.base_url'), '/');
        $this->transcriptionModel = (string) config('services.openai.transcription_model');
        $this->transcriptionLanguage = (string) config('services.openai.transcription_language', 'es');
        $this->soapModel = (string) config('services.openai.soap_model');
        $this->soapEffort = (string) config('services.openai.soap_effort', 'low');
        $this->timeout = (int) config('services.openai.timeout', 120);
    }

    public function transcribe(UploadedFile $audio, ?string $language = null, ?string $prompt = null): array
    {
        $this->ensureConfigured();

        if ((int) $audio->getSize() === 0) {
            throw new InvalidArgumentException('El archivo de audio llego vacio.');
        }

        $handle = fopen($audio->getRealPath(), 'r');

        if ($handle === false) {
            throw new RuntimeException('No se pudo leer el archivo de audio.');
        }

        try {
            $response = Http::withToken($this->apiKey())
                ->timeout($this->timeout)
                ->attach(
                    'file',
                    $handle,
                    $audio->getClientOriginalName(),
                    ['Content-Type' => $audio->getMimeType() ?: 'application/octet-stream']
                )
                ->post($this->url('/audio/transcriptions'), array_filter([
                    'model' => $this->transcriptionModel,
                    'response_format' => 'json',
                    'language' => $language ?: $this->transcriptionLanguage,
                    'prompt' => $prompt,
                ], fn ($value) => filled($value)));
        } finally {
            fclose($handle);
        }

        $this->throwIfFailed($response);

        $text = $this->extractTranscriptionText($response);

        if ($text === '') {
            Log::warning('OpenAI transcription response did not include text.', [
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'json_keys' => $this->jsonKeys($response),
                'body_length' => strlen($response->body()),
                'audio_name' => $audio->getClientOriginalName(),
                'audio_mime' => $audio->getMimeType(),
                'audio_size' => $audio->getSize(),
            ]);

            throw new RuntimeException('No se detecto voz en el audio. Revisa que el microfono haya grabado sonido y vuelve a intentarlo.');
        }

        return [
            'text' => $text,
            'model' => $this->transcriptionModel,
        ];
    }

    public function draftConsultation(string $transcript, ?Patient $patient = null, ?string $context = null): array
    {
        $this->ensureConfigured();

        $transcript = trim($transcript);

        if ($transcript === '') {
            throw new InvalidArgumentException('La transcripcion no puede estar vacia.');
        }

        $response = Http::withToken($this->apiKey())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->post($this->url('/responses'), [
                'model' => $this->soapModel,
                'store' => false,
                'reasoning' => [
                    'effort' => $this->soapEffort,
                ],
                'text' => [
                    'verbosity' => 'low',
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'clinical_consultation_draft',
                        'strict' => true,
                        'schema' => $this->draftSchema(),
                    ],
                ],
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => $this->systemPrompt(),
                            ],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => $this->userPrompt($transcript, $patient, $context),
                            ],
                        ],
                    ],
                ],
            ]);

        $this->throwIfFailed($response);

        $draft = json_decode($this->extractOutputText($response->json()), true);

        if (! is_array($draft)) {
            throw new RuntimeException('OpenAI devolvio una respuesta que no se pudo interpretar.');
        }

        return [
            'draft' => $this->normalizeDraft($draft),
            'model' => $this->soapModel,
            'usage' => $response->json('usage'),
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Eres un formateador clinico SOAP. Tu unica funcion es reorganizar una transcripcion en campos SOAP editables.

Reglas:
- Responde solo con el JSON solicitado por el esquema.
- Usa espanol claro y profesional.
- No diagnostiques, no opines, no recomiendes y no hagas inferencias clinicas.
- No inventes sintomas, signos vitales, antecedentes, resultados, medicamentos, diagnosticos, impresiones ni planes.
- Copia y organiza solo informacion explicitamente mencionada en la transcripcion.
- Todos los campos y subcampos del esquema son obligatorios.
- Si un campo textual del documento SOAP no aparece en la transcripcion, escribe exactamente "no especificado".
- Si un signo vital no aparece en la transcripcion, escribe exactamente "no especificado" en ese campo.
- No uses null, cadenas vacias, "N/A", "sin datos" ni frases equivalentes.
- No rellenes un campo por intuicion: cuando no haya evidencia textual, usa "no especificado".
- No pegues toda la transcripcion en un solo campo: clasifica cada dato en su campo SOAP correspondiente.
- Dentro de cada subcampo textual, usa lineas cortas tipo lista comenzando con "- " cuando haya mas de un dato.
- En subjective separa sintomas, inicio/evolucion, factores asociados, negativos pertinentes, exposiciones y antecedentes mencionados.
- En objective coloca solo examen fisico, signos vitales, observaciones medibles o resultados mencionados.
- Copia los signos vitales mencionados tambien en vital_signs, ademas de objective si aplica.
- El campo assessment debe contener valoraciones, impresiones, diagnosticos o frases equivalentes presentes en la transcripcion, aunque no usen la palabra "diagnostico" o "evaluacion".
- El campo plan debe contener indicaciones, tratamientos, examenes, seguimiento, reposo, hidratacion, medicamentos o acciones presentes en la transcripcion, aunque no usen la palabra "plan".
- No agregues en assessment ni plan nada que no este sustentado por una frase de la transcripcion.
PROMPT;
    }

    private function userPrompt(string $transcript, ?Patient $patient, ?string $context): string
    {
        $patientContext = $patient
            ? "Paciente: {$patient->full_name}. DNI: {$patient->dni}."
            : 'Paciente: no especificado.';

        $extraContext = filled($context)
            ? "Contexto adicional indicado por el medico: {$context}"
            : 'Contexto adicional indicado por el medico: ninguno.';

        return <<<PROMPT
{$patientContext}
{$extraContext}

Transcripcion:
{$transcript}
PROMPT;
    }

    private function draftSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'description' => 'Motivo principal de consulta en una frase breve, maximo 255 caracteres. Si no fue mencionado, usar "no especificado".',
                ],
                'subjective' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'chief_complaint' => $this->textField('Queja o motivo principal referido por el paciente.'),
                        'history_of_present_illness' => $this->textField('Inicio, duracion, evolucion, intensidad, localizacion y contexto del problema actual.'),
                        'symptoms' => $this->textField('Sintomas positivos mencionados por el paciente.'),
                        'pertinent_negatives' => $this->textField('Sintomas negados o negativos pertinentes mencionados explicitamente.'),
                        'exposures' => $this->textField('Exposiciones, contactos, viajes, alimentos u otros factores de riesgo mencionados.'),
                        'past_medical_history' => $this->textField('Antecedentes personales, quirurgicos, familiares o condiciones previas mencionadas.'),
                        'medications' => $this->textField('Medicamentos actuales, recientes o automedicacion mencionada.'),
                        'allergies' => $this->textField('Alergias mencionadas.'),
                    ],
                    'required' => $this->subjectiveFields(),
                ],
                'objective' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'physical_exam' => $this->textField('Hallazgos del examen fisico u observaciones clinicas objetivas.'),
                        'measurable_findings' => $this->textField('Datos medibles u observables que no sean signos vitales.'),
                        'test_results' => $this->textField('Resultados de pruebas, examenes, laboratorios o imagenes mencionados.'),
                        'vital_signs' => $this->vitalSignsSchema(),
                    ],
                    'required' => [
                        'physical_exam',
                        'measurable_findings',
                        'test_results',
                        'vital_signs',
                    ],
                ],
                'assessment' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'impressions' => $this->textField('Impresiones o valoraciones clinicas expresadas en la transcripcion.'),
                        'diagnoses' => $this->textField('Diagnosticos mencionados explicitamente o por frases equivalentes.'),
                        'clinical_reasoning' => $this->textField('Razonamiento clinico expresado por quien habla, sin agregar inferencias nuevas.'),
                    ],
                    'required' => $this->assessmentFields(),
                ],
                'plan' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'medications' => $this->textField('Medicamentos indicados, dosis o cambios de tratamiento mencionados.'),
                        'tests' => $this->textField('Pruebas, laboratorios, imagenes o estudios solicitados.'),
                        'procedures' => $this->textField('Procedimientos, curaciones, terapias o intervenciones indicadas.'),
                        'recommendations' => $this->textField('Indicaciones generales como hidratacion, reposo, dieta, ejercicio u otras medidas.'),
                        'follow_up' => $this->textField('Seguimiento, controles, citas o reevaluacion indicada.'),
                        'return_precautions' => $this->textField('Signos de alarma o instrucciones para regresar/consultar de nuevo.'),
                        'patient_education' => $this->textField('Educacion al paciente o explicaciones dadas.'),
                    ],
                    'required' => $this->planFields(),
                ],
            ],
            'required' => [
                'reason',
                'subjective',
                'objective',
                'assessment',
                'plan',
            ],
        ];
    }

    private function textField(string $description): array
    {
        return [
            'type' => 'string',
            'description' => $description.' Si no aparece explicitamente en la transcripcion, usar exactamente "no especificado".',
        ];
    }

    private function vitalSignsSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'temperature' => $this->textField('Temperatura mencionada, con unidad si aparece.'),
                'blood_pressure' => $this->textField('Presion arterial mencionada.'),
                'heart_rate' => $this->textField('Frecuencia cardiaca o pulso mencionado.'),
                'respiratory_rate' => $this->textField('Frecuencia respiratoria mencionada.'),
                'oxygen_saturation' => $this->textField('Saturacion de oxigeno mencionada.'),
                'weight' => $this->textField('Peso mencionado.'),
                'height' => $this->textField('Talla o estatura mencionada.'),
                'other' => $this->textField('Otros signos vitales o mediciones vitales mencionadas.'),
            ],
            'required' => $this->vitalSignFields(),
        ];
    }

    private function normalizeDraft(array $draft): array
    {
        if (! $this->hasStructuredSoapSections($draft)) {
            return $this->normalizeLegacyDraft($draft);
        }

        $structured = $this->normalizeStructuredDraft($draft);

        return [
            'reason' => mb_substr($structured['reason'], 0, 255),
            'subjective' => $this->renderSections([
                'Motivo principal' => $structured['subjective']['chief_complaint'],
                'Historia del padecimiento actual' => $structured['subjective']['history_of_present_illness'],
                'Sintomas' => $structured['subjective']['symptoms'],
                'Negativos pertinentes' => $structured['subjective']['pertinent_negatives'],
                'Exposiciones' => $structured['subjective']['exposures'],
                'Antecedentes' => $structured['subjective']['past_medical_history'],
                'Medicamentos referidos' => $structured['subjective']['medications'],
                'Alergias' => $structured['subjective']['allergies'],
            ]),
            'objective' => $this->renderSections([
                'Examen fisico' => $structured['objective']['physical_exam'],
                'Hallazgos medibles' => $structured['objective']['measurable_findings'],
                'Resultados de pruebas' => $structured['objective']['test_results'],
                'Signos vitales' => $this->renderVitalSigns($structured['objective']['vital_signs']),
            ]),
            'assessment' => $this->renderSections([
                'Impresiones' => $structured['assessment']['impressions'],
                'Diagnosticos' => $structured['assessment']['diagnoses'],
                'Razonamiento clinico' => $structured['assessment']['clinical_reasoning'],
            ]),
            'plan' => $this->renderSections([
                'Medicamentos' => $structured['plan']['medications'],
                'Pruebas solicitadas' => $structured['plan']['tests'],
                'Procedimientos' => $structured['plan']['procedures'],
                'Recomendaciones' => $structured['plan']['recommendations'],
                'Seguimiento' => $structured['plan']['follow_up'],
                'Signos de alarma' => $structured['plan']['return_precautions'],
                'Educacion al paciente' => $structured['plan']['patient_education'],
            ]),
            'vital_signs' => $structured['objective']['vital_signs'],
            'structured' => $structured,
        ];
    }

    private function normalizeLegacyDraft(array $draft): array
    {
        $vitalSigns = Arr::get($draft, 'vital_signs', []);

        $normalized = [
            'reason' => mb_substr($this->normalizeTextField(Arr::get($draft, 'reason')), 0, 255),
            'subjective' => $this->normalizeTextField(Arr::get($draft, 'subjective')),
            'objective' => $this->normalizeTextField(Arr::get($draft, 'objective')),
            'assessment' => $this->normalizeTextField(Arr::get($draft, 'assessment')),
            'plan' => $this->normalizeTextField(Arr::get($draft, 'plan')),
            'vital_signs' => $this->normalizeVitalSigns(is_array($vitalSigns) ? $vitalSigns : []),
        ];

        $normalized['structured'] = $this->structuredFromLegacyDraft($normalized);

        return $normalized;
    }

    private function hasStructuredSoapSections(array $draft): bool
    {
        return is_array(Arr::get($draft, 'subjective'))
            || is_array(Arr::get($draft, 'objective'))
            || is_array(Arr::get($draft, 'assessment'))
            || is_array(Arr::get($draft, 'plan'));
    }

    private function normalizeStructuredDraft(array $draft): array
    {
        $objective = Arr::get($draft, 'objective', []);
        $vitalSigns = is_array($objective)
            ? Arr::get($objective, 'vital_signs', [])
            : [];

        if (! is_array($vitalSigns)) {
            $vitalSigns = [];
        }

        return [
            'reason' => mb_substr($this->normalizeTextField(Arr::get($draft, 'reason')), 0, 255),
            'subjective' => $this->normalizeFieldGroup(Arr::get($draft, 'subjective'), $this->subjectiveFields()),
            'objective' => [
                ...$this->normalizeFieldGroup($objective, [
                    'physical_exam',
                    'measurable_findings',
                    'test_results',
                ]),
                'vital_signs' => $this->normalizeVitalSigns($vitalSigns),
            ],
            'assessment' => $this->normalizeFieldGroup(Arr::get($draft, 'assessment'), $this->assessmentFields()),
            'plan' => $this->normalizeFieldGroup(Arr::get($draft, 'plan'), $this->planFields()),
        ];
    }

    private function normalizeFieldGroup(mixed $group, array $fields): array
    {
        $group = is_array($group) ? $group : [];
        $normalized = [];

        foreach ($fields as $field) {
            $normalized[$field] = $this->normalizeTextField(Arr::get($group, $field));
        }

        return $normalized;
    }

    private function structuredFromLegacyDraft(array $draft): array
    {
        return [
            'reason' => $draft['reason'],
            'subjective' => [
                'chief_complaint' => $draft['reason'],
                'history_of_present_illness' => $draft['subjective'],
                'symptoms' => self::UNSPECIFIED,
                'pertinent_negatives' => self::UNSPECIFIED,
                'exposures' => self::UNSPECIFIED,
                'past_medical_history' => self::UNSPECIFIED,
                'medications' => self::UNSPECIFIED,
                'allergies' => self::UNSPECIFIED,
            ],
            'objective' => [
                'physical_exam' => $draft['objective'],
                'measurable_findings' => self::UNSPECIFIED,
                'test_results' => self::UNSPECIFIED,
                'vital_signs' => $draft['vital_signs'],
            ],
            'assessment' => [
                'impressions' => $draft['assessment'],
                'diagnoses' => self::UNSPECIFIED,
                'clinical_reasoning' => self::UNSPECIFIED,
            ],
            'plan' => [
                'medications' => self::UNSPECIFIED,
                'tests' => self::UNSPECIFIED,
                'procedures' => self::UNSPECIFIED,
                'recommendations' => $draft['plan'],
                'follow_up' => self::UNSPECIFIED,
                'return_precautions' => self::UNSPECIFIED,
                'patient_education' => self::UNSPECIFIED,
            ],
        ];
    }

    private function renderSections(array $sections): string
    {
        $lines = [];

        foreach ($sections as $label => $value) {
            $value = $this->normalizeTextField($value);

            if ($this->isUnspecified($value)) {
                $lines[] = "{$label}: ".self::UNSPECIFIED;
                continue;
            }

            $items = $this->splitTextLines($value);

            if (count($items) === 1) {
                $lines[] = "{$label}: {$items[0]}";
                continue;
            }

            $lines[] = "{$label}:";

            foreach ($items as $item) {
                $lines[] = "- {$item}";
            }
        }

        return $lines === [] ? self::UNSPECIFIED : implode("\n", $lines);
    }

    private function renderVitalSigns(array $vitalSigns): string
    {
        $labels = [
            'temperature' => 'Temperatura',
            'blood_pressure' => 'Presion arterial',
            'heart_rate' => 'Frecuencia cardiaca',
            'respiratory_rate' => 'Frecuencia respiratoria',
            'oxygen_saturation' => 'Saturacion de oxigeno',
            'weight' => 'Peso',
            'height' => 'Talla',
        ];

        $lines = [];

        foreach ($labels as $key => $label) {
            $lines[] = "{$label}: ".$this->normalizeTextField(Arr::get($vitalSigns, $key));
        }

        $other = $this->normalizeOtherVitalSigns(Arr::get($vitalSigns, 'other', []));
        $lines[] = 'Otros: '.($other === [] ? self::UNSPECIFIED : implode('; ', $other));

        return implode("\n", $lines);
    }

    private function normalizeTextField(mixed $value): string
    {
        $value = trim((string) $value);

        if ($this->isUnspecified($value)) {
            return self::UNSPECIFIED;
        }

        return $value;
    }

    private function isUnspecified(mixed $value): bool
    {
        $value = trim((string) $value);

        return $value === '' || preg_match('/^no\s+especificad[oa]\.?$/iu', $value) === 1;
    }

    private function splitTextLines(string $value): array
    {
        $lines = preg_split('/\R+/', trim($value)) ?: [];
        $items = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/^\s*-\s*/', '', trim($line)));

            if ($line !== '' && ! $this->isUnspecified($line)) {
                $items[] = $line;
            }
        }

        return $items === [] ? [self::UNSPECIFIED] : $items;
    }

    private function normalizeVitalSigns(array $vitalSigns): array
    {
        return [
            'temperature' => $this->normalizeTextField(Arr::get($vitalSigns, 'temperature')),
            'blood_pressure' => $this->normalizeTextField(Arr::get($vitalSigns, 'blood_pressure')),
            'heart_rate' => $this->normalizeTextField(Arr::get($vitalSigns, 'heart_rate')),
            'respiratory_rate' => $this->normalizeTextField(Arr::get($vitalSigns, 'respiratory_rate')),
            'oxygen_saturation' => $this->normalizeTextField(Arr::get($vitalSigns, 'oxygen_saturation')),
            'weight' => $this->normalizeTextField(Arr::get($vitalSigns, 'weight')),
            'height' => $this->normalizeTextField(Arr::get($vitalSigns, 'height')),
            'other' => $this->normalizeOtherVitalSigns(Arr::get($vitalSigns, 'other', [])),
        ];
    }

    private function normalizeOtherVitalSigns(mixed $otherVitalSigns): array
    {
        if (is_string($otherVitalSigns)) {
            if ($this->isUnspecified($otherVitalSigns)) {
                return [];
            }

            return $this->splitTextLines($otherVitalSigns);
        }

        if (! is_array($otherVitalSigns)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $otherVitalSigns),
            fn (string $value) => $value !== '' && ! $this->isUnspecified($value)
        ));
    }

    private function subjectiveFields(): array
    {
        return [
            'chief_complaint',
            'history_of_present_illness',
            'symptoms',
            'pertinent_negatives',
            'exposures',
            'past_medical_history',
            'medications',
            'allergies',
        ];
    }

    private function assessmentFields(): array
    {
        return [
            'impressions',
            'diagnoses',
            'clinical_reasoning',
        ];
    }

    private function planFields(): array
    {
        return [
            'medications',
            'tests',
            'procedures',
            'recommendations',
            'follow_up',
            'return_precautions',
            'patient_education',
        ];
    }

    private function vitalSignFields(): array
    {
        return [
            'temperature',
            'blood_pressure',
            'heart_rate',
            'respiratory_rate',
            'oxygen_saturation',
            'weight',
            'height',
            'other',
        ];
    }

    private function extractTranscriptionText(Response $response): string
    {
        $jsonText = $response->json('text');

        if (is_string($jsonText) && trim($jsonText) !== '') {
            return trim($jsonText);
        }

        $body = trim($response->body());

        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return trim((string) Arr::get($decoded, 'text', ''));
        }

        return $body;
    }

    private function jsonKeys(Response $response): array
    {
        $payload = $response->json();

        return is_array($payload) ? array_keys($payload) : [];
    }

    private function extractOutputText(array $payload): string
    {
        $outputText = Arr::get($payload, 'output_text');

        if (is_string($outputText) && trim($outputText) !== '') {
            return $outputText;
        }

        foreach (Arr::get($payload, 'output', []) as $item) {
            foreach (Arr::get($item, 'content', []) as $content) {
                $text = Arr::get($content, 'text');

                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        }

        throw new RuntimeException('OpenAI no devolvio contenido de salida.');
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?: $response->json('message')
            ?: 'No se pudo completar la solicitud a OpenAI.';

        throw new RuntimeException($message, $response->status());
    }

    private function ensureConfigured(): void
    {
        if (blank($this->apiKey())) {
            throw new InvalidArgumentException('OPENAI_API_KEY no esta configurada.');
        }
    }

    private function apiKey(): ?string
    {
        return config('services.openai.key');
    }

    private function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }
}
