# Pruebas de extremo a extremo (Playwright)

Recorren el asistente completo (pasos 1 a 6) en un navegador real contra una pila en marcha,
hasta dejar el informe del paso 6 con una versión aprobada y comprobar cada descarga:
Next, Laravel y el servicio de IA. No arrancan nada: usan la URL de `E2E_BASE_URL`
(por defecto `https://localhost:8443`, la pila local «como producción»).

Lo ideal es un usuario verificado **sin caracterización previa**, porque el paso 1 se rellena
desde cero. Si el usuario ya tiene el paso 1 guardado, la prueba lo reutiliza y solo lo edita
para completar el LEI y el periodo del informe cuando faltan (los exige el XBRL). Crear uno en el
backend (`app/web`):

```
php artisan tinker --execute "App\Models\User::factory()->create(['name' => 'Prueba E2E', 'email' => 'e2e@example.test', 'password' => Illuminate\Support\Facades\Hash::make('cambia-esta-clave')]);"
```

Lanzar:

```
E2E_EMAIL=e2e@example.test E2E_PASSWORD='cambia-esta-clave' corepack pnpm test:e2e
```

Variables: `E2E_BASE_URL`, `E2E_EMAIL`, `E2E_PASSWORD`, `E2E_BROWSER_CHANNEL` (`chrome` por
defecto, el Chrome del sistema; `chromium` requiere `pnpm exec playwright install chromium`),
`E2E_BROWSER_PATH` (otro navegador basado en Chromium, p. ej. `/opt/brave.com/brave/brave`) y
`E2E_SLOW_MO` (milisegundos de pausa entre acciones). Para verla en pantalla: `corepack pnpm test:e2e --headed`.
El informe HTML queda en `test-results/html`.

## Qué cubre `wizard.spec.ts`

- Paso 1: caracterización de una PYME industrial (NACE elegido del catálogo, preguntas de actividad).
- Paso 2: propuesta con el servicio de IA y decisión en cada tema.
- Paso 3: acta del análisis. Paso 4: confirmación de la selección final.
- Paso 5: inventario rápido por estándar y decisión en los 190 datos: 12 con cifras de la empresa,
  hasta 16 completados en total y el resto «No aplica» justificado. Las cifras se registran con su
  tipo (entero, importe, número), unidad y decimales en la propia respuesta: son los hechos del
  informe y lo que el XBRL etiqueta, sin volver a introducirlas en el paso 6.
- Paso 6: comprueba por `GET /api/report/facts` que cada respuesta completada con valor es ya un hecho
  revisado del informe, prepara y aprueba la versión del informe y verifica que cada descarga responde
  con su formato: borrador y trazabilidad JSON, paquete HTML, informe HTML y DOCX, paquete de
  evidencias, hoja de decisión, CSV de datos y respuestas y PDF de caracterización.
- Documento XBRL: con la taxonomía EFRAG, su manifiesto y Arelle aprovisionados (`GET /api/report/taxonomy`
  en `verified`), descarga el candidato XHTML/iXBRL y el paquete iXBRL (ZIP con `reports/candidate.xhtml`),
  comprueba los cinco hechos numéricos etiquetados (`ix:nonFraction` con sus conceptos `esrs:*`), el LEI y la
  cabecera `X-Taxonomy-Assertions`, y adjunta ambos ficheros al informe de Playwright. Sin taxonomía, ese paso
  se omite y la prueba deja constancia de que el candidato aparece bloqueado.

Dura unos 3 minutos contra la pila local (Arelle valida cada documento en ~25 s). Las descargas se piden con `page.request`, que
comparte las cookies de la sesión iniciada en el navegador.

### Paso 5 con el mapa AR16 activo

Con `ESRS_MATTER_DR_MAPPING_PATH` activo (por defecto desde el 2026-11-17) el inventario pasa de 190
datos a varios cientos. La prueba rellena por la interfaz los datos de NEIS 2 y marca el inventario
rápido de todos los estándares; los datos temáticos que queden pendientes se deciden en bloque por la
API `PUT /api/esrs-datapoints/responses` con la sesión del navegador («No aplica» justificado), porque
abrirlos uno a uno cuesta unos tres segundos por fila. La comprobación del paso 6 usa el tamaño del
inventario, así que también cuenta lo decidido en ejecuciones anteriores del mismo usuario.

No lances dos ejecuciones seguidas sin esperar: si se interrumpe una, su navegador sigue vaciando la
cola de autoguardados unos minutos y la siguiente recibirá un conflicto de revisión en el paso 5.

## Empresas ficticias y vídeos (`demo-walkthrough.spec.ts`)

Las cinco cuentas `test1..5@airis.com` se generan en el servidor con `php artisan i4s:demo:companies`
(ver `app/docs/demo-companies.md`). Este spec no rellena nada: entra con cada cuenta, recorre los
seis pasos con los datos ya generados y guarda el vídeo en `app/web/storage/app/private/demo-videos/`,
de donde lo descargan los administradores en `/admin`. Variables: `DEMO_COMPANIES_PASSWORD` y,
opcionalmente, `DEMO_COMPANIES_ONLY` (correos separados por comas).
