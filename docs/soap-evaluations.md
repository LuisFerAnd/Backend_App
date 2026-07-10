# Evaluaciones SOAP

## Instalación

Desde `backend_App`:

```bash
composer install
php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
```

La zona horaria usa `APP_TIMEZONE` y, si no se define, `America/Tegucigalpa`.

Las dependencias PHP `phpoffice/phpspreadsheet` y `flobee/spss` generan XLSX y SAV, respectivamente. El backend vuelve a abrir cada SAV con PHP antes de entregarlo; si la verificación falla, el endpoint no descarga un archivo inválido. `flobee/spss` requiere las extensiones PHP `bcmath` y `mbstring`.

## Prueba funcional

1. Inicie el backend con `php artisan serve` y la app Flutter con `flutter run --dart-define=SANARE_API_URL=http://HOST:8000/api`.
2. Ingrese como doctor, genere y guarde una consulta SOAP.
3. Abra Pacientes, seleccione el paciente y pulse **Evaluar registro clínico**.
4. Compruebe el código diario, los datos automáticos, el avance por secciones y los estados `Guardando…`, `Guardado` y `Sin conexión: cambios pendientes`.
5. Cierre y vuelva a abrir la app para comprobar la recuperación cifrada desde `flutter_secure_storage`.
6. Guarde un borrador; luego responda todos los reactivos y finalice.
7. Ingrese como administrador, abra **Evaluaciones**, busque o filtre y descargue XLSX, CSV y SAV. Cada fila puede exportarse individualmente desde su menú.

El CSV usa UTF-8 con BOM y encabezados; el XLSX contiene `Datos` y `Diccionario`; el SAV contiene etiquetas de variables y valores. Ninguno incluye paciente, audio, transcripción ni texto SOAP.

## Pruebas automatizadas

```bash
cd backend_App && php artisan test
cd ../sanare_mobile && flutter analyze && flutter test
```

Las pruebas cubren correlativo diario y reinicio, unicidad por consulta, cálculos, conteos, control de versión, validación de finalización, autorización, filtros y apertura válida de XLSX/SAV.

## API

- `GET /api/consultations/{id}/soap-evaluation`: obtiene o crea idempotentemente la ficha.
- `PUT /api/soap-evaluations/{id}`: guarda borrador con `version` optimista.
- `POST /api/soap-evaluations/{id}/complete`: valida y finaliza idempotentemente.
- `GET /api/admin/soap-evaluations`: listado paginado con búsqueda, estado, fechas, evaluador, especialidad y orden.
- `GET /api/admin/soap-evaluations/export/{csv|xlsx|sav}`: exportación total, filtrada o individual.

Los permisos sembrados son `evaluations.view_own`, `evaluations.create`, `evaluations.update_own`, `evaluations.view_all` y `evaluations.export`. La auditoría de exportación se conserva en `soap_evaluation_exports`.
