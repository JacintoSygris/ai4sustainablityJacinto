# Pruebas de extremo a extremo (Playwright)

Recorren el asistente completo (pasos 1 a 6) en un navegador real contra una pila en marcha,
hasta dejar el informe del paso 6 con versión factual aprobada y comprobar cada descarga:
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
  hasta 16 completados en total y el resto «No aplica» justificado. Cada dato completado exige en el
  paso 6 un dato factual revisado uno a uno y la revisión está limitada a 20 por minuto, de ahí el tope.
- Paso 6: primer dato factual y su revisión por la interfaz; el resto se carga por la API con la
  sesión del navegador (`PUT /api/report/facts`, `POST /api/report/facts/{id}/review`), se prepara y
  aprueba la versión factual y se comprueba que cada descarga responde con su formato: borrador y
  trazabilidad JSON, paquete HTML, HTML y DOCX factuales, paquete de evidencias, hoja de decisión,
  CSV de datos y respuestas y PDF de caracterización.
- Documento XBRL: con la taxonomía EFRAG, su manifiesto y Arelle aprovisionados (`GET /api/report/taxonomy`
  en `verified`), descarga el candidato XHTML/iXBRL y el paquete iXBRL (ZIP con `reports/candidate.xhtml`),
  comprueba los cinco hechos numéricos etiquetados (`ix:nonFraction` con sus conceptos `esrs:*`), el LEI y la
  cabecera `X-Taxonomy-Assertions`, y adjunta ambos ficheros al informe de Playwright. Sin taxonomía, ese paso
  se omite y la prueba deja constancia de que el candidato aparece bloqueado.

Dura unos 3 minutos contra la pila local (Arelle valida cada documento en ~25 s). Las descargas se piden con `page.request`, que
comparte las cookies de la sesión iniciada en el navegador.

