# Notas de sesiones con Claude

Resumen vivo de lo averiguado, acordado y pendiente en las conversaciones sobre este repositorio (`JacintoSygris/ai4sustainablityJacinto`), para retomar el trabajo sin repetirlo. No es una transcripción. Nunca se guardan secretos ni datos personales.

## Hallazgos y respuestas

- **Camino a producción (2026-11-16)** — producción (`cbi-airis-pro-backend` con Laravel bajo PM2 y `cbi-airis-pro-frontend` con Next) despliega desde este repositorio público, no desde el privado `JacintoSygris/ia4sustainability`. Las dos historias no comparten commits y nunca se fusionan: los cambios del privado se portan a mano o con `cherry-pick`. Secuencia: commit aquí → `git push origin main` → en el backend `git pull`, `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan db:seed --force` (catálogos NACE y NEIS, idempotente), `php artisan i4s:deploy:check`, `pm2 restart` de Laravel y del worker `airis-queue` → en el frontend `git pull`, `corepack pnpm install --frozen-lockfile`, `corepack pnpm build`, `pm2 restart` de Next → comprobar `GET /api/auth/register-config`. El 16/11 la clave SSH del equipo Linux (`JesusRio1991`) solo tenía lectura sobre el repo público: el push lo rechazaba GitHub. Los hosts de producción no resuelven desde ese equipo; el acceso previo se hizo desde el Mac.
- **Turnstile retirado (2026-11-13 en el privado, rehecho aquí el 2026-11-16, sin commit)** — el registro ya no depende de Cloudflare Turnstile: no hay clave de sitio, secreto, widget, comprobación en servidor ni `cf-turnstile-response`. La protección antibots es el campo trampa (honeypot) y el límite de 5 altas por IP cada 10 minutos. `RegistrationGuard::registrationUnavailableReasons()` sigue existiendo para `i4s:deploy:check`, pero en producción solo exige `AUTH_REQUIRE_EMAIL_VERIFICATION=true`, `QUEUE_CONNECTION` duradera, `MAIL_MAILER` que no exponga el enlace y `APP_URL` canónico https. Las variables `TURNSTILE_*` que queden en un `.env` se ignoran. Se retiraron `public/consent/security.mjs` (web y frontend), su prueba, el tipo `turnstile_site_key` y las menciones en cookies, privacidad y documentación es-ES/en-GB.
- **Registro con correo repetido (2026-11-16)** — antes era deliberadamente indistinguible de un alta nueva (anti-enumeración) y el usuario no recibía nada. Por decisión del propietario ahora `RegisteredUserController` valida `unique:users,email` y la web muestra «Ya existe una cuenta con este correo electrónico.» (422; el formulario Next ya pintaba el primer error de validación). El coste conocido es que un tercero puede comprobar si un correo está dado de alta.
- **«La sesión de seguridad ha caducado» al recuperar contraseña (2026-11-16)** — no era Cloudflare. Con `AUTH_PASSWORD_RESET_ENABLED=false` Laravel responde 404 a `/laravel/forgot-password`, la página Next no obtenía token CSRF y lo mostraba como 419. Ahora `GET /api/auth/register-config` devuelve `password_reset_enabled`; la página de recuperación muestra «La recuperación de contraseña no está disponible en este momento…» cuando está desactivada y los 4xx reales con su mensaje; el login oculta el enlace cuando está desactivada. En producción, si se quiere la recuperación, hay que poner `AUTH_PASSWORD_RESET_ENABLED=true` en `app/web/.env` del backend (exige cola `database`, mailer seguro y origen https).
- **Entorno local «como producción» (Linux, 2026-11-16)** — Laravel con `APP_ENV=production` exige HTTPS y host de confianza, y Next en producción exige `LARAVEL_CANONICAL_ORIGIN` https. Montaje: proxy Caddy (`caddy:2`, `tls internal`, `https://localhost:8443`) → Next compilado (`corepack pnpm build` / `start` en 127.0.0.1:3125) → Laravel en la imagen local `i4s-php:8.4` (la oficial `php:8.4-cli` no trae `zip`, `gd`, `intl`…) con `--network host`, `php artisan serve` en 8127 y un contenedor `queue:work` → servicio de IA en 8011. El PHP del sistema (8.4 del PPA de Ondřej, sin paquetes para focal) no sirve; `composer install` se ejecuta dentro del contenedor montando el `composer` del host. `app/web/.env` sale de `.env.production.example` con `APP_URL=https://localhost:8443`, `TRUSTED_HOSTS=localhost,127.0.0.1`, SQLite en `database/database.sqlite`, `MAIL_MAILER=sendmail` hacia un script que guarda `.eml` en `~/.config/i4s-local/mail/` (leerlos con `~/.config/i4s-local/stack mail`, que decodifica el *quoted-printable*: en crudo el `=` aparece como `=3D`), registro y recuperación activados, `CHARACTERIZATION_GATEWAY=api` hacia `http://127.0.0.1:8011`. `app/frontend/.env.local` con `NEXT_PUBLIC_APP_URL` y `LARAVEL_CANONICAL_ORIGIN=https://localhost:8443`, `LARAVEL_API_ORIGIN`/`INTERNAL=http://127.0.0.1:8127`. Assets Blade con `npm install && npm run build` en `app/web`. En un checkout recién clonado faltan `storage/framework/{cache,sessions,views}` y `bootstrap/cache` (ignorados por git): sin ellos `php artisan optimize` falla con «View path not found»; crearlos con `mkdir -p` antes del primer arranque. Usuario demo verificado creado con tinker; sus credenciales están fuera del repo en `~/.config/i4s-local/jacinto-demo-user.txt`. Script fuera del repo: `~/.config/i4s-local/stack start|stop|status|logs|mail` (`I4S_REPO=` permite apuntar a otro checkout). Ojo: el proceso de Next se llama `next-server`; el script lo para por el puerto 3125, porque si queda uno vivo de una build anterior las páginas no se hidratan (botones deshabilitados, sin mensajes de error).
- **Servicio de IA en local (2026-11-16)** — el modelo `new_format_732_v1_gpt41` está en `app/ai-service/model-artifacts/gpt41` (idéntico al privado). Imagen `i4s-ai-public:latest` construida con `docker build -f Dockerfile.public` desde `app/ai-service` (copia el modelo a `trained_classifier/new_format/gpt41`); se arranca con `uvicorn public_model_app:app --host 127.0.0.1 --port 8011` y `I4S_AI_MODEL_PROFILE=new_format_732_v1_gpt41`. Se usa el 8011 porque el 8001 lo ocupa `dev-sygris-front`. El aviso `sector_snapshot_unavailable` al arrancar es normal. Comprobación desde Laravel: `php artisan characterization:smoke-api-gateway --base-url=http://127.0.0.1:8011`.

- **Página `/reset-password` mal pintada (2026-11-16)** — la sirve Laravel (Blade, Next solo la reescribe hacia el backend). Su Tailwind 3 usaba `darkMode` por `prefers-color-scheme`, así que con el sistema en oscuro los inputs de Breeze salían negros (`dark:bg-gray-900`) y el botón claro sobre una página clara; Next limita `dark:` a la clase `.dark`. Arreglo: `darkMode: 'class'` en `app/web/tailwind.config.js` y `npm run build`. Además los layouts Blade no declaraban icono y `/favicon.ico` daba 404 (en `app/web/public` era un fichero vacío y Next no tenía ninguno): por eso extensiones como Bitwarden iban a buscarlo a `icons.bitwarden.net`. Ahora hay `favicon.ico` real (generado con `convert` desde `icon-light-32x32.png`) en los `public` de web y frontend, copia de `icon.svg`/`apple-icon.png` en `app/web/public` y `<link rel="icon">` en `guest.blade.php` y `app.blade.php`. Next sirve los ficheros nuevos de `public/` solo tras reiniciar `next start`. El aviso de cookies va fijo abajo (`.airis-consent-notice`), pero los controles persistentes «Configurar consentimiento / Retirar opcionales» (`.airis-consent-controls`, flujo normal) salían encima de la cabecera porque `#airis-consent-root` estaba al principio del `body` en los layouts Blade; ahora va al final del `body`, como `<CookieConsent />` en el layout de Next, y queda bajo el pie. El botón «Restablecer contraseña» tampoco coincidía con los de Next (Breeze: gris, mayúsculas): ahora `tailwind.config.js` de `app/web` extiende los colores con los tokens claros de `app/frontend/app/globals.css` pasados a hex (`primary #4838bf`, `foreground`, `muted-foreground`, `border`/`input`, `ring`, `destructive`) y los componentes Breeze `text-input`, `primary-button`, `input-label` e `input-error` replican `components/ui/{input,button,label}.tsx`; `auth/reset-password.blade.php` tiene la misma estructura que `forgot-password/page.tsx` (título, subtítulo, botón a ancho completo, «Volver a iniciar sesión» hacia `/login`), con dos cadenas nuevas en `lang/es.json`. Afecta también a las vistas Blade de `confirm-password`, `forgot-password`, `verify-email` y los formularios de perfil, que usan los mismos componentes. Para pasar los tests de Laravel contra la pila local hay que hacer `config:clear` antes (rechazan la configuración cacheada) y `optimize` después.

- **Verificación de cuenta en producción (2026-11-16, sin commit)** — tras registrarse, el enlace del correo redirigía a `http://10.167.39.221:8000/login`: el registro no inicia sesión, la ruta `verify-email/{id}/{hash}` está bajo `auth`, y Laravel construía la URL de login con el host interno con el que Next (en otra máquina, no incluida en `TRUSTED_PROXIES`) le reenvía la petición. Ahora `AppServiceProvider` fuerza `URL::forceRootUrl(CanonicalPublicUrl::root())` y `forceScheme('https')` en producción (si `APP_URL` no es válido, se omite y sigue el aviso de siempre); prueba en `tests/Feature/CanonicalUrlTest.php`. Alternativa/complemento operativo: añadir la IP del frontend a `TRUSTED_PROXIES` en el backend. Flujo esperado: primer clic → login público → el panel exige verificación → segundo clic en el enlace (o reenvío) → verificado. El botón del correo salía con dos grises en clientes con modo oscuro (Gmail invierte el fondo neutro `#18181b` pero no los bordes): tema `resources/views/vendor/mail/html/themes/airis.css` (copia del de Laravel con `#4838bf` en botón y enlaces) activado por `config/mail.php` (`MAIL_MARKDOWN_THEME`, por defecto `airis`). Al desplegar hay que volver a ejecutar `php artisan optimize` porque cambia la configuración cacheada.

- **Ayuda, panel y perfil igualados (2026-11-16, sin commit)** — `/help` tenía su propia página sin cabecera ni pie: nuevo `app/help/layout.tsx` que muestra `DashboardHeader` con sesión o `AuthHeader` sin ella, más `Footer`, y se retira el `LanguageSelector` duplicado de la página. Las tarjetas de «Academia Airis» (`components/dashboard/academy-section.tsx`) no enlazaban a nada: ahora abren en pestaña nueva artículos de sygris.com (doble materialidad en es/en, análisis de materialidad y grupos de interés, Grenergy y reporte no financiero). `/profile` es Blade: `layouts/navigation.blade.php` replica la cabecera del panel de Next (marca, «¿Necesitas ayuda?», idioma que se aplica al cambiar, avatar con iniciales y menú Configuración/Perfil/Cerrar sesión con Alpine), `layouts/app.blade.php` usa el mismo contenedor que el panel, y `profile/edit.blade.php` muestra una sola columna de tarjetas a ancho completo (`max-w-3xl`) sin la tarjeta «Identidades sociales» (decisión del propietario; las rutas de vinculación siguen existiendo y `SocialIdentityTest` ahora comprueba que el perfil no la ofrece). Capturas con sesión iniciada verificadas en local. Segunda pasada: la cabecera Blade usa exactamente el marcado y las clases de `dashboard-header.tsx` (`container mx-auto px-4`, selector de idioma nativo con `appearance-auto bg-none pr-2` para anular `@tailwindcss/forms`, avatar `border-accent bg-accent`, menú `bg-popover`), con los tokens `secondary`, `accent` y `popover` añadidos al Tailwind de Laravel. La diferencia de tipografía venía de Next: `globals.css` declara `--font-sans: "Geist"` pero ningún fichero de Geist se carga, así que el panel caía a la fuente por defecto del navegador (serif). Sin añadir dependencias (la instalación de `geist` fue denegada por el modo automático), ahora ambos lados usan la misma pila `Geist, ui-sans-serif, system-ui, …`: cambia el aspecto de todas las páginas Next en producción, de serif a sans.

- **Asistente homogéneo (2026-11-16, sin commit)** — en «Qué vas a hacer aquí» (`components/wizard/wizard-expectations.tsx`) la etiqueta «Paso N:» se partía en dos líneas: ahora `shrink-0 whitespace-nowrap`. Los `Select` de shadcn (`components/ui/select.tsx`) tenían `w-fit` y en «Datos oficiales de la empresa» salían al ancho del texto: el disparador pasa a `w-full`, como `Input`, para toda la aplicación. Los `<select>` nativos del asistente (identificación XBRL, importación DMA, datos NEIS) usan la misma clase que `Input` (`h-9 w-full rounded-md border border-input bg-background px-3 text-sm` + anillo de foco); los de identificación XBRL pasan de una fila `flex-wrap` a una rejilla de dos columnas. Verificado con captura del paso 1 con sesión. Después, por decisión propia validada con el propietario: el panel «Qué vas a hacer aquí» ya no enumera los seis pasos (duplicaba la barra lateral, y las etiquetas «Paso N:» se pegaban al texto); conserva la introducción y tres tarjetas «Cuánto tiempo lleva» (los tiempos orientativos que antes iban por paso), «Qué necesitas» y «Qué obtienes». `consent-consumers.test.mjs` comprueba ahora el nuevo texto en inglés.

- **Catálogos NACE y temas NEIS vacíos (2026-11-16)** — `GET /api/nace-codes` existe (`NaceCodeController`, requiere sesión) y el buscador del paso 1 funciona, pero la tabla `nace_codes` estaba vacía en la pila local y seguramente en producción: el catálogo se carga con `php artisan db:seed --force` (`NaceCodeSeeder` lee `app/web/data/nace_codes.json`, 1060 códigos; `EsrsTopicSeeder` carga los 89 temas NEIS que usa el paso 2). Ambos son `upsert`, así que el comando es idempotente y seguro en producción. Falta en la secuencia de «Camino a producción» tras `migrate --force`. En local ya está sembrado.

- **Prueba de extremo a extremo del asistente con Playwright (2026-11-16, sin commit)** — `@playwright/test` 1.63 como dependencia de desarrollo del frontend (instalado desde el almacén de pnpm sin red), `app/frontend/playwright.config.ts` (usa el Chrome del sistema con `channel: chrome`, así no descarga Chromium; `E2E_BASE_URL` por defecto `https://localhost:8443`), `tests/e2e/wizard.spec.ts` y `tests/e2e/README.md`; script `corepack pnpm test:e2e`; `test-results/` ignorado. La prueba inicia sesión con `E2E_EMAIL`/`E2E_PASSWORD`, rellena el paso 1 con una PYME industrial (Talleres Mecánicos del Henares, NACE C25.6 elegido del catálogo, 50-249 empleados, EUR 10M-40M, preguntas de actividad), genera la propuesta con la IA real, acepta los temas (el último en duda), registra el acta del paso 3, confirma el paso 4, en el paso 5 marca el inventario rápido de cada estándar (NEIS 2 «Lo tengo», el resto «Tengo que buscarlo»), pasa a modo «Responder» y contesta los 12 primeros datos con cifras de la empresa (consejo, plantilla, emisiones de alcance 1 y 2, energía, residuos, accidentes, formación, proveedores, canal de denuncias, riesgo climático) con su evidencia y las dos preguntas de revisión, marcando uno de cada cuatro como «No aplica» con justificación; guarda y comprueba el informe del paso 6 («Datos decididos»). En español los grupos se llaman «NEIS 2», no «ESRS 2». Con el usuario de la ejecución los temas temáticos salen bloqueados («Falta el mapa aprobado AR16 a DR»), así que solo hay datos de NEIS 2 (190). Tarda unos 25 s contra la pila local. Si el usuario ya tiene encuesta y revisión guardadas, los pasos 1 y 2 se reutilizan. Usuarios de prueba locales: `e2e-*@i4s.local` creados con `User::factory()` por tinker (contraseña en el historial de esta sesión, no en el repo).

- **Paso 5 como acordeón con panel estructurado (2026-11-16, sin commit)** — en `components/wizard/esrs-datapoints-form.tsx` cada dato normativo se abre de uno en uno (abrir uno cierra el resto; `setExpandedDatapoints({ [id]: true })` también en los enlaces profundos desde IRO y NLP); en modo «Responder» ya no se despliegan todos a la vez. La fila muestra id en monoespaciada, nombre, y dos chips (obligación y estado con color: completado verde, no aplica ámbar, borrador gris). El panel abierto se divide en tres bloques: «Contexto del dato» (gris, solo si hay contexto), «Revisión del dato» (tarjeta blanca con las dos preguntas en dos columnas, motivo y nota) y «Respuesta» (tarjeta con tinte primario: estado, valor, evidencia y nota). Ids, etiquetas y textos se conservan; cadenas nuevas «Contexto del dato» y «Respuesta» en `messages.mjs` y `wave2-messages.mjs`. La prueba E2E abre cada fila antes de rellenarla y comprueba que solo hay un formulario abierto.
- **Paso 6 completo en la prueba E2E (2026-11-16, sin commit)** — reglas del informe (`ReportController`): «Listo» exige todos los datos obligatorios decididos, un dato factual revisado por cada dato *completado* (`ReportContentReadiness`), y las salidas factuales (HTML, DOCX, evidencias) una versión factual preparada y aprobada con declaración de persona única. La revisión de datos factuales está limitada a 20/min (`throttle:20,1,report-facts-review`) y no hay revisión en bloque, por eso la prueba completa 16 datos y marca el resto «No aplica» justificado; el primer dato factual se crea y revisa por la interfaz y el resto por la API con la sesión del navegador. Comprueba estado, formato y contenido de 10 descargas (JSON, HTML, DOCX como ZIP, CSV, PDF). XHTML solo aparece con `REPORT_SCOPE_INTEGRITY_ENABLED=true`; el candidato iXBRL exige `ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH` y Arelle, no configurados en local (ni en el `.env` de ejemplo), así que la tarjeta «Taxonomía NEIS externa» sale «Bloqueado». Dura ~2,2 min.

- **Documento XBRL generado de verdad (2026-11-17, sin commit)** — el candidato XHTML/iXBRL exige fuera del repositorio: el paquete oficial `ESRS-Set1-XBRL-Taxonomy.zip` de EFRAG (https://xbrl.efrag.org/downloads/ESRS-Set1-XBRL-Taxonomy.zip, 1,8 MB, SHA-256 `f9dab985…850b3`), un manifiesto privado (`external_taxonomy_manifest_v1`, perfil `esrs-2023-preparatory-v1`, entrypoint `…/2023-12-22/esrs_all.xsd`), Arelle (`arelle-release` 2.45.1 en un venv) y una caché de dependencias para validar sin red. En local: `~/.config/i4s-local/external/{taxonomy,arelle-cache}` montado en `/opt/i4s-external`, imagen `i4s-php:8.4-arelle` (= `i4s-php:8.4` + `apt python3 python3-venv` + `requirements-arelle.txt` en `/opt/arelle`, receta de `Dockerfile.public`; Dockerfile en `~/.config/i4s-local/arelle-image`), la caché se aprovisionó con una pasada `--internetConnectivity=online` sobre el entrypoint, y en `app/web/.env`: `IXBRL_PACKAGE_UI_ENABLED=true`, `ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH=/opt/i4s-external/taxonomy/manifest.json`, `ESRS_ARELLE_COMMAND=/opt/arelle/bin/arelleCmdLine`, `ESRS_ARELLE_CACHE_DIRECTORY=/opt/i4s-external/arelle-cache`. Con eso `GET /api/report/taxonomy` pasa a `verified`. Tres defectos del producto impedían que Arelle aceptara el documento: (1) el XHTML no era XHTML 1.1 (atributo `lang`, `<meta charset>`, elementos `<section>` y atributos `data-claim-id`), que es lo que exige Inline XBRL; `ReportPresentationDocument` emite ahora `xml:lang`, `meta http-equiv`, `<div class="section">` y `class="fact <claim_id>"` (el renderizador localiza el párrafo por esa clase). (2) El validador bloqueaba ante cualquier entrada `error` del registro, y la taxonomía EFRAG emite ~90 errores y ~320 avisos de aserción (`message:ea_eu_tags_*`, «siempre obligatorio sin etiquetar») para cualquier informe que no etiquete todos los datos del Apéndice B, cosa imposible con el etiquetado solo numérico: ahora bloquean los errores estructurales (esquema, XBRL 2.1, iXBRL, `formula:*` insatisfechas) y las aserciones `message:*` se cuentan y viajan en la cabecera `X-Taxonomy-Assertions: errors=N; warnings=M` de ambas descargas (`ArelleXhtmlIxbrlValidator::validate` devuelve el resumen). (3) El presupuesto de salida era 1 MiB y el registro JSON real pesa 1,5 MB: ahora 8 MiB, configurable con `ESRS_ARELLE_MAX_OUTPUT_BYTES`. Tests de validador y renderizador actualizados (78/78); los 27 fallos restantes de `tests/Unit/Report` + `ReportApiTest` son de entorno (`mkdir` sin permiso, `../contracts` fuera del montaje, `XBRL_GENERIC_TAXONOMY_ROOT` sin definir). La prueba E2E rellena LEI válido (`959800TLLRSHENARES33`, sintético) y periodo 2025 en el paso 1, completa cinco datos numéricos con correspondencia a concepto (GOV-1_01/02/07, SBM-1_03, SBM-1_06) con hechos `integer`/`number`/`monetary` y unidades `pure`/`%`/`EUR`, y descarga el candidato (5 `ix:nonFraction`) y el paquete ZIP validado por Arelle (~25 s por documento). El etiquetado sigue limitado a hechos numéricos sin dimensiones (`atomizer_xbrl_concepts_v1.json`); otra taxonomía = nuevo perfil en `config/ixbrl.php` + `data/reporting_profiles/*.json` + mapa de conceptos + manifiesto externo, el perfil 2026 ya existe pero sin correspondencias.

- **Botón «Generar XBRL» en el paso 6 y avisos retirados (2026-11-17, sin commit)** — `report-draft-panel.tsx`: botón principal «Generar XBRL» en la cabecera que, según el estado, prepara la versión factual, abre el diálogo de aprobación (declaración de persona única, exigida por el backend) y descarga el paquete iXBRL por `fetch` con la sesión (`informe-neis-ixbrl.zip`), mostrando «Documento XBRL generado y validado» con el resumen de `X-Taxonomy-Assertions`; si faltan requisitos, lista qué hacer (datos del paso 5, datos factuales revisados, flag `IXBRL_PACKAGE_UI_ENABLED`, taxonomía aprovisionada). Por decisión del propietario desaparecen la tarjeta ámbar «Alcance y límites de estas salidas», el aviso «El paquete organiza preparación NEIS 2023…», la tarjeta «Modo alcance» y la tarjeta «Limitaciones»; el enlace de la versión aprobada pasa a «Descargar XBRL (paquete iXBRL)». Tests de Node ajustados (`public-launch-surfaces`, `p10-step6-laravel-boundary`). La taxonomía sigue mostrándose como tarjeta informativa.

## Pendiente

- **Push a `origin/main`** — los cambios están confirmados en local (`07861d4` retirada de Turnstile y registro/recuperación; `e44162f` páginas Blade igualadas con Next, iconos y consentimiento) pero el push falla: el remoto HTTPS no tiene credenciales guardadas y la clave SSH del equipo Linux (`JesusRio1991`) solo tiene lectura (`Permission denied`). Hace falta dar permiso de escritura a esa cuenta o empujar desde el Mac / con un token.
- **Desplegar en producción** siguiendo la secuencia de «Camino a producción» y decidir `AUTH_PUBLIC_REGISTRATION_ENABLED` / `AUTH_PASSWORD_RESET_ENABLED` en el `.env` del backend.

## Registro de sesiones

- **2026-11-16** — Checkout nuevo en `/var/cbi/ai4sustainablityJacinto`. Rehecha a mano la retirada de Turnstile (tomando como referencia el porte del 13/11 en el privado), aplicados el error visible de correo repetido y el arreglo de recuperación de contraseña, dependencias instaladas, pruebas en verde y pila local «como producción» levantada con IA real desde este repo. Sin commit por decisión del propietario.

## Ejemplo completo con un usuario que ya tenía el paso 1 (2026-11-17, sin commit)

- `tests/e2e/wizard.spec.ts`: cuando la encuesta inicial ya está guardada, la prueba entra en
  «Editar la encuesta inicial» y solo completa el LEI y el periodo del informe si faltan
  (los exige el candidato iXBRL); si ya están, recarga y continúa sin volver a guardar.
- Ejecutado con un usuario local que tenía el paso 1 de una ejecución antigua sin LEI: pasa en
  3,2 min y deja el informe en «Listo» (190/190, versión factual aprobada, taxonomía verificada,
  botón «Generar XBRL» activo). README del E2E actualizado con esta condición.

## Revisión frente a los objetivos tecnológicos del proyecto (2026-11-17, sin commit)

Se contrastaron los cinco objetivos (caracterización con extracción de información y PLN;
recomendador con filtrado colaborativo, aprendizaje profundo y ajuste por retroalimentación;
motor de datos clave alineado con la doble materialidad; agrupación/clasificación y aprendizaje
incremental; estructuración normativa y XBRL) con el código real. Lo que faltaba y se ha hecho:

- **`POST /extract-document`** en el servicio de IA (`public_runtime/services/document_extraction.py`
  y `document_extraction_endpoint.py`): Laravel ya lo llamaba desde `ExtractCharacterizationDocumentJob`,
  pero la ruta no existía. Lee PDF (`pypdf`, nueva dependencia en `requirements.public.txt`) o DOCX
  (stdlib), parte en frases por página y recupera evidencia de temas NEIS con TF-IDF frente a
  `esrs_topics.json` + `ar16_to_python_esrs_mapping.json`; frases negadas → evidencia `negative`.
  Flags: `I4S_DOCUMENT_EXTRACTION_ENABLED` (off), `ESRS_UPLOAD_EXTRACT_BASE` (lista blanca de rutas),
  `I4S_DOCUMENT_EXTRACTION_CATALOGUE_DIR` (catálogo fuera de un checkout). Probado con contenedor
  temporal y DOCX de dos páginas: 3 evidencias correctas (E1 positiva p.1, E2 agua negativa p.2, E1
  energía p.2). Laravel sigue exigiendo ClamAV para abrir la subida (`P6DocumentUploadGuard`).
- **Filtrado colaborativo** (`src/collaborative_filtering.py`): similitud coseno ítem-ítem entre claves
  NEIS según decisiones confirmadas; se ajusta en cada entrenamiento supervisado y añade candidatos
  co-confirmados para claves no cubiertas por la superposición (soporte ≥ 5 empresas, similitud media
  ≥ 0,5, máximo 5). Nunca retira claves. Entra en el control de promoción como parte del modelo.
- **Red neuronal** en el entrenamiento supervisado: `LEARNING_ENGINE_ESTIMATOR=rf|mlp|auto`
  (MLP 64×32 con escalado; `auto` elige por validación cruzada enmascarada). Recibo con
  `estimator_family`, `estimator_selection`, `collaborative_filtering`; respuesta servida con
  `mapping_metadata.learning_overlay`.
- `code_hashes()`, `.dockerignore` y `Dockerfile.public` incluyen el módulo nuevo; imagen
  `i4s-ai-public:latest` reconstruida y contenedor `i4s-ai` reiniciado con ella.
- Pruebas nuevas: `test_document_extraction.py` (7) y `test_collaborative_filtering.py` (10).
  Suite completa del servicio: 248 pruebas, mismos 18 fallos preexistentes que en HEAD (faltan
  `jsonschema`/`optuna` en la imagen pública y rutas `web/data`), ninguno nuevo.
- `CONCEPT_RELATIONS_ENABLED=true` en el `.env` local (datos estáticos del repo; 68 temas con
  relaciones). Documentación actualizada en es/en (document-extraction, configuration,
  architecture, ai-mapping-reporting, acceptance-diagnostics, secrets-and-parameters, README del
  servicio, README raíz, `.env.production.example`, learning-engine-production).

Candidatos a retirar (pendiente de decisión): estudio de usabilidad, login social, greentech,
pila de curación learning-case (≈20 servicios y 15 tablas sin claves de config), `recommend()`
legado, ficheros muertos del frontend (`archive/`, onboarding, `esg-topics-data.ts`, primitivas
shadcn sin uso), `sector_columns.pkl`/`region_columns.pkl`, dependencias `mlflow`/`pandera`.

## Retirada del login social y del código muerto del frontend (2026-11-17, sin commit)

- **Login social (Laravel)**: borrados `SocialLoginController`, `OAuthIdentity`, `OAuthIdentityDescriptor/Resolver`,
  los parciales Blade de opciones sociales e identidades, `SocialIdentityTest`; rutas `social.*`, el
  listener de Socialite en `AppServiceProvider`, la relación en `User`, los bloques `social_login`/`google`/`microsoft`
  de `config/services.php`, 14 cadenas de `lang/en.json`, las variables `SOCIAL_LOGIN_ENABLED`/`GOOGLE_*`/`MICROSOFT_*`
  de `.env.example`, `.env.production.example`, `.env` local y `compose.public.yml`. Paquetes `laravel/socialite`,
  `socialiteproviders/microsoft` y `socialiteproviders/manager` fuera de `composer.json` y `composer.lock`
  (ejecutado con la imagen `composer:2`; solo salieron esos paquetes y sus dependencias transitivas).
  Nueva migración `2026_11_17_000001_drop_oauth_identities` (aplicada en local); `users.auth_version` se conserva.
  Tras quitar los paquetes hay que borrar `bootstrap/cache/packages.php` y `services.php` y ejecutar
  `php artisan package:discover`, si no la aplicación no arranca (`SocialiteProviders\Manager\ServiceProvider not found`).
- **Frontend**: páginas de cookies y privacidad sin la mención a Google/Microsoft (sección renombrada «Servicios
  externos»), claves i18n retiradas, tests `public-launch-surfaces` y `localization-wave2` ajustados.
- **Docs**: `mail-oauth.md` (es/en) queda solo con SMTP; retiradas las referencias OAuth/Socialite en scope,
  secrets-and-parameters, acceptance-diagnostics, references, operations, limits/capabilities, deployment-boundaries e index.
- **Código muerto del frontend borrado**: `archive/2026-06-06-imported-next-backend/` (19 ficheros), `components/auth/onboarding-step-{one,two}.tsx`,
  `components/theme-provider.tsx`, `lib/esg-topics-data.ts`, `hooks/use-{mobile,toast}.ts`, `components/ui/use-{mobile,toast}.*`
  y 36 primitivas shadcn sin importar (accordion, alert-dialog, aspect-ratio, breadcrumb, button-group, calendar, carousel,
  chart, command, context-menu, drawer, field, form, hover-card, input-group, input-otp, item, kbd, menubar, navigation-menu,
  pagination, popover, progress, radio-group, resizable, scroll-area, sidebar, slider, sonner, spinner, switch, table, tabs,
  toaster, toggle, toggle-group). Con ellas, 29 dependencias npm que solo usaban esos ficheros (`pnpm remove --offline`).
  `persistSidebar` y la cookie `sidebar_state` desaparecen del helper de consentimiento y de la página de cookies
  (el núcleo de consentimiento conserva la limpieza de esa cookie heredada).
- Tests adaptados: `dependency-readiness` (chart.tsx ya no existe), `integration-boundaries` (archivo retirado),
  `p10-taxonomy-boundary` (ahora exige «Generar XBRL»), `consent-consumers` (sin bloque de la barra lateral),
  `a11y-i18n-readiness` (lista de primitivas). Además `esrs-datapoints-form.tsx` usa la forma funcional de
  `setExpandedDatapoints` en `openNlpIndicator`, que un test de P9 ya exigía.
- Verificación: `tsc` limpio; 564/564 tests de Node; build de Next y reinicio en 3125; Laravel Auth/Profile/UserFacingCopy
  78/78; `/login` y `/cookies` sin menciones a Google/Microsoft.
- Sin tocar (pendiente de decisión): estudio de usabilidad, greentech, pila learning-case, `recommend()` legado,
  `.pkl` sin cargar y `mlflow`/`pandera` del servicio de IA.

## Mapa AR16 → DR activado en local (2026-11-17, sin commit)

El aviso del paso 5 «Falta el mapa aprobado AR16 a DR…» sale cuando `ESRS_MATTER_DR_MAPPING_PATH` está vacío.
El fichero aprobado ya viene en el repo (`app/web/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json`, 89 temas,
83 requisitos, `php artisan esrs:validate-matter-dr-mapping` → VALID). En local se ha apuntado a
`/app/data/...` (ruta dentro del contenedor). Efecto con el usuario de prueba: de 190 datos (solo NEIS 2) a 789
(146 siempre exigibles, 599 temáticos, 44 de divulgación mínima). En producción hay que añadir la variable con la
ruta absoluta del checkout del backend y limpiar la caché de configuración.

## Mapa AR16 → DR activo por defecto (2026-11-17)

Sin acceso al servidor de producción, el mapa se activa desde el código: `config/services.php` usa
`data/ar16_to_esrs_dr_mapping_esrs2023_v1.json` cuando `ESRS_MATTER_DR_MAPPING_PATH` está vacía o no existe;
`off` desactiva los datos temáticos y una ruta absoluta lo sustituye. `phpunit.xml` fija `off` para que la
suite conserve el comportamiento histórico (los tests que necesitan el mapa lo configuran explícitamente).
De paso, `GuidedReportApiTest` tenía un doble de `ArelleXhtmlIxbrlValidator::validate` con la firma antigua
(`void`) y hacía fallar el fichero entero; devuelve ahora el array de aserciones.

## Prueba E2E adaptada al inventario completo (2026-11-17)

- Los grupos temáticos del paso 5 se llaman «E1 (214)», «S1 (174)»… sin prefijo NEIS; la prueba ya
  los reconoce (selectores `[^·]+`) y marca su inventario rápido.
- Los datos temáticos pendientes se deciden en bloque por `PUT /api/esrs-datapoints/responses`
  (exige `expected_revision`, tomado de `response_state.revision` del workspace, y sustituye el
  conjunto completo, así que se reenvían las respuestas guardadas). Por la interfaz costaba ~3 s por
  fila y superaba el tiempo límite.
- La comprobación «N de N datos ya decididos» usa el tamaño del inventario.
- Falsa alarma investigada: un 409 `datapoint_responses_conflict` al primer autoguardado venía del
  navegador de una ejecución interrumpida que seguía vaciando su cola; no es un fallo del formulario.
- Local, usuario de prueba con 789 datos: pasa en 2,5 min. En producción (669 datos en la cuenta del
  usuario) basta con relanzar el mismo comando.

## PDF del resumen en producción (2026-11-17)

La prueba E2E contra producción llegó al paso 6 y solo falló `/characterization/summary?format=pdf`
con HTTP 500: `barryvdh/laravel-dompdf` estaba en `require-dev` y el despliegue instala sin dev.
Movido a `require` (lock refrescado con la imagen `composer:2`; solo se mueven dompdf y sus
dependencias). Tras el siguiente despliegue del backend la descarga PDF funciona.

## XBRL sin acceso al servidor: aprovisionamiento automático (2026-11-17)

Producción no tiene `.env` editable, así que todo lo que exigía el botón «Generar XBRL» se resuelve desde el código:
- `IXBRL_PACKAGE_UI_ENABLED` pasa a `true` por defecto (`filter_var`, porque PHPUnit convierte `value="false"` en cadena vacía).
- `ExternalAssetRoot`: raíz de activos externos (`ESRS_EXTERNAL_ROOT` o `<hermano del checkout>/i4s-external`, luego
  `$HOME/.local/share`, luego `/tmp`), nunca dentro de `base_path` ni `storage_path`.
- `ExternalTaxonomyProvisioner` + comando `esrs:provision-external-taxonomy`: descarga el ZIP de EFRAG (SHA-256 fijado
  en `config/ixbrl.php`), lo guarda en `<raíz>/taxonomy/` y escribe el manifiesto. Lo lanza `GET /api/report/taxonomy`.
  Probado contra EFRAG real: 1.882.168 bytes, verificado.
- `ArelleCommandLocator`: `ESRS_ARELLE_COMMAND` → `.venv/bin/arelleCmdLine` del servicio de IA (`arelle-release==2.45.1`
  añadido a `requirements.public.txt`; resuelve con numpy 2.5.3 en Python 3.12) → `/opt/arelle` → `/healthz` del
  servicio de IA (nuevo bloque `arelle.command`). `ESRS_ARELLE_PROBE=false` lo desactiva (así va en `phpunit.xml`).
- Caché de Arelle por defecto en `<raíz>/arelle-cache`; primer uso con red (job `WarmArelleCache` desde el estado de
  taxonomía, o inline en la primera validación) y marca `.i4s-bootstrapped`; después, sin red. Medido en local: 55 s el
  calentamiento, ~26 s cada validación posterior. Ojo: el proxy local corta a 30 s, de ahí el job en cola.
- `GET /api/report/taxonomy` añade `provisioning` y `validator` (solo estados). El paso 6 muestra un bloqueo distinto
  cuando falta Arelle.
- Local: `.env` sin manifiesto/Arelle/caché explícitos y `ESRS_EXTERNAL_ROOT=/opt/i4s-external/auto` (montado desde
  `~/.config/i4s-local/external/auto`); el flujo completo funcionó por la API.
