# Flujo híbrido de audio

## Despliegue

La aplicación utiliza la conexión de cola configurada en `QUEUE_CONNECTION`. La configuración recomendada inicial es:

```env
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=240
AUDIO_SEGMENT_MAX_KB=20480
AUDIO_SINGLE_TRANSCRIPTION_MAX_KB=24576
OPENAI_TIMEOUT=120
FFMPEG_BINARY=ffmpeg
FFMPEG_TIMEOUT=45
```

Las tablas `jobs`, `job_batches` y `failed_jobs` ya forman parte del proyecto. Para desplegar:

```bash
php artisan migrate --force
php artisan queue:work --queue=default --tries=5 --timeout=200 --max-time=3600
```

El servidor debe tener FFmpeg disponible para consolidar varios M4A. En Ubuntu/Debian puede instalarse con `apt install ffmpeg`; `FFMPEG_BINARY` permite indicar otra ruta. Si FFmpeg no está disponible o la consolidación falla, el sistema cambia automáticamente a transcripción segmentada sin perder los audios.

En producción, el worker debe ejecutarse mediante Supervisor, systemd o el gestor de procesos de la plataforma. `stopwaitsecs` debe ser mayor de 200 segundos. Después de desplegar código nuevo, ejecutar `php artisan queue:restart`.

## Límites del servidor

Valores orientativos de `php.ini`:

```ini
upload_max_filesize = 25M
post_max_size = 30M
max_execution_time = 300
max_input_time = 300
memory_limit = 256M
```

Nginx:

```nginx
client_max_body_size 30M;
proxy_connect_timeout 60s;
proxy_send_timeout 180s;
proxy_read_timeout 180s;
```

Apache debe permitir al menos 30 MB mediante `LimitRequestBody 31457280`. Los timeouts del proxy/FastCGI deben ser de al menos 180 segundos.

## Endpoints

- `POST /api/consultations/start`
- `POST /api/consultations/{consultation}/segments`
- `POST /api/consultations/{consultation}/finalize`
- `GET /api/consultations/{consultation}/processing-status`
- `GET /api/consultations/{consultation}/missing-segments`
- `POST /api/consultations/{consultation}/segments/{segment}/retry-transcription`
- `POST /api/consultations/{consultation}/retry-processing`

Todos requieren autenticación médica. Las subidas son idempotentes por `(session_uuid, segment_number)`. Un reenvío con el mismo checksum responde correctamente; otro contenido para el mismo número devuelve HTTP 409.

## Procesamiento híbrido

1. El móvil conserva y sube un fragmento M4A aproximadamente cada 5 minutos. La subida responde sin llamar a OpenAI.
2. `FinalizeConsultationJob` verifica cantidades y todos los números desde 1 hasta `expected_segments`.
3. Si la suma de los fragmentos no supera 24 MB y aún no comenzó otra transcripción, selecciona la estrategia `single`.
4. `TranscribeConsultationAudioJob` consolida los M4A y realiza una sola llamada de transcripción.
5. Si el audio supera 24 MB, falta FFmpeg, la unión falla o el resultado consolidado rebasa el umbral, selecciona `segmented` y despacha un `TranscribeAudioSegmentJob` por fragmento.
6. Solo en el fallback, `MergeTranscriptionsJob` ordena las transcripciones y elimina solapamientos textuales idénticos en los bordes.
7. `GenerateSoapJob` genera un único SOAP con la transcripción final.

El umbral interno deja 1 MB de margen frente al límite actual de 25 MB de la API de transcripción. La decisión se basa en bytes, no en duración. Con AAC mono a 48 kbps, 5 minutos ocupan aproximadamente 1.8 MB.

Los fragmentos del servidor quedan en `storage/app/private/consultations/{session_uuid}/segments` y el archivo unido, cuando aplica, en `storage/app/private/consultations/{session_uuid}/consolidated`. La limpieza debe ejecutarse mediante una política posterior y únicamente después de `processing_status=completed` y del periodo de retención definido por la organización.

`consultations.transcription_strategy` registra `single` o `segmented`; `consolidated_audio_path` y `consolidated_audio_size` permiten auditar la ruta elegida sin guardar audio ni transcripciones en los logs.

## Diagnóstico

```bash
php artisan queue:failed
php artisan queue:retry <uuid-del-job>
php artisan queue:monitor default:100
php artisan test
```

Los logs contienen identificadores técnicos, checksum, tamaño y estados; no incluyen audio ni transcripciones completas.
