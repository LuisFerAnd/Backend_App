# Registro y evaluación de consultas fallidas

## Regla principal

`POST /api/consultations/start` es idempotente por `session_uuid` y crea, antes de grabar:

- la consulta y su `consultation_code` permanente;
- el intento de procesamiento número 1;
- el borrador del instrumento de evaluación.

El móvil crea primero `LOCAL-YYYYMMDD-UUIDCORTO` en SQLite. Si no hay conexión, usa ese código durante la grabación y sincroniza posteriormente el mismo `session_uuid`; el backend responde con el código oficial sin perder la referencia local.

## Estados

La consulta conserva estados independientes de grabación, carga, transcripción, SOAP, PDF y evaluación. `overall_status` resume el resultado, mientras `failure_stage`, `failure_code`, `failure_message`, `user_friendly_error_message` y `failure_occurred_at` describen el fallo.

Los mensajes técnicos no deben contener nombres, conversación, transcripción ni diagnóstico.

## Intentos y reintentos

`consultation_processing_attempts` conserva cada intento. Un reintento agrega una fila; no modifica ni elimina el intento fallido anterior. El instrumento guarda `processing_attempt_id` para identificar qué resultado fue evaluado.

## Evaluación sin SOAP

Cuando `soap_status != completed`, los criterios de estructura SOAP y de errores se guardan internamente con `98` (`No aplica: no se generó SOAP`) y no intervienen en el puntaje. CSV y XLSX exportan esos criterios como celdas vacías; SAV conserva el `98` etiquetado y declarado como valor perdido para excluirlo de los cálculos de SPSS. Se requieren las preguntas técnicas del intento fallido y las escalas de aceptación. Esto diferencia un fallo técnico de un SOAP generado con deficiencias.

Las exportaciones CSV, XLSX y SAV incluyen código de consulta, estados técnicos, etapa/código de fallo, segmentos, generación SOAP/PDF, intento evaluado, tipo de resultado y observaciones profesionales.

## Despliegue

```bash
php artisan migrate
php artisan queue:restart
php artisan queue:work --tries=5 --timeout=200 --max-time=3600
```

Antes de producción se recomienda respaldar la base y probar la migración sobre una copia, especialmente si existen consultas históricas.

## Prueba manual mínima

1. Desactive Internet e inicie una consulta: debe aparecer un código `LOCAL-...` antes de encender el micrófono.
2. Cierre y abra la aplicación: la sesión debe continuar en SQLite.
3. Reactive Internet: debe obtener código `C-...` conservando el UUID.
4. Simule un fallo de subida o IA y abra el historial: la consulta debe mostrar estado y etapa del fallo.
5. Abra **Evaluar** sin SOAP: debe aceptar `No aplica` y permitir completar las preguntas técnicas.
6. Reintente el procesamiento: el intento fallido debe permanecer y debe crearse el siguiente intento.
7. Exporte CSV/XLSX/SAV y confirme que la consulta fallida está incluida.
