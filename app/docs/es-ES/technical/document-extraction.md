# Extracción documental

Volver al [manual técnico](index.md).

## Estado actual

El servicio de IA implementa `POST /extract-document` (`app/ai-service/public_runtime/services/document_extraction.py`). Está desactivado por defecto y solo responde a peticiones autenticadas de Laravel.

- Laravel acepta PDF/DOCX de hasta 50 MB con `P6_DOCUMENT_UPLOAD_ENABLED=true` (también exige el análisis ClamAV configurado; ver `P6DocumentUploadGuard`).
- El job `ExtractCharacterizationDocumentJob` llama a `POST {CHARACTERIZATION_API_BASE_URL}/extract-document` con el token `CHARACTERIZATION_API_TOKEN`.
- El servicio lee el fichero de `document_path`, que debe estar bajo uno de los directorios de `ESRS_UPLOAD_EXTRACT_BASE` (rutas absolutas separadas por el separador de rutas del sistema). Ambos procesos deben ver la misma ruta: mismo host o volumen de almacenamiento compartido.
- El texto se extrae con `pypdf` (PDF, con número de página) o de `word/document.xml` (DOCX, las páginas siguen los saltos de página renderizados). Las frases se comparan con el catálogo de temas NEIS mediante TF-IDF (n-gramas de palabra 1–2 y de carácter 3–5). Las frases negadas se devuelven como evidencia `negative`. No se entrena nada con los documentos ni se registra más que códigos de estado.
- El catálogo es `esrs_topics.json` más `ar16_to_python_esrs_mapping.json`: `web/data` en un checkout, o el directorio de `I4S_DOCUMENT_EXTRACTION_CATALOGUE_DIR` (móntelo en solo lectura en la imagen).

## Configuración

Proceso Python:

```dotenv
I4S_DOCUMENT_EXTRACTION_ENABLED=true
I4S_AI_API_TOKEN=<mismo valor que CHARACTERIZATION_API_TOKEN>
ESRS_UPLOAD_EXTRACT_BASE=/var/www/html/storage/app/private
I4S_DOCUMENT_EXTRACTION_CATALOGUE_DIR=/opt/i4s-catalogues   # solo fuera de un checkout
```

Laravel: `P6_DOCUMENT_UPLOAD_ENABLED=true`, `P6_DOCUMENT_SCAN_ENABLED=true` con `clamscan` y los tiempos `P6_DOCUMENT_EXTRACT_TIMEOUT` / `P6_DOCUMENT_EXTRACT_JOB_TIMEOUT`. El servicio dedica como máximo 150 segundos por documento y procesa uno cada vez (`503 extraction_busy` si está ocupado; el job reintenta una vez).

## Contrato

Petición:

```json
{
  "document_id": "123",
  "document_path": "/var/www/html/storage/app/private/characterization-documents/1/ejemplo.pdf",
  "original_filename": "ejemplo.pdf",
  "sha256": "<sha256 del fichero almacenado>"
}
```

Respuesta (el job de Laravel la valida de forma estricta):

```json
{
  "document_id": "123",
  "sha256": "<mismo hash>",
  "status": "ok",
  "parser_version": "v3",
  "config_version": "gate-run-4",
  "evidence": [
    {"standard": "E1", "topic_key": "esrs_e1_adaptation_to_climate_change", "kind": "positive",
     "confidence": 0.61, "page": 1, "snippet": "..."}
  ]
}
```

- `status`: `ok` (documento `extracted`), `no_usable_evidence` (sin texto o sin coincidencia de temas) o `error` con un `message` breve (`path_not_allowed`, `hash_mismatch`, `unsupported_format`, `unreadable_document`, `file_too_large`, `catalogue_unavailable`).
- Como máximo 100 filas, 3 por tema y tipo, fragmentos de hasta 300 caracteres; `topic_key` es la clave NEIS de Python de una correspondencia aprobada de un solo tema.
- HTTP: 404 desactivado, 503 sin token o ocupado, 401 con token incorrecto, 413/422 con cuerpo excesivo o inválido.

## Pruebas

`src/tests/test_document_extraction.py` cubre el análisis de DOCX y PDF, el contrato de las filas, los fallos de lista blanca y de hash y el control del endpoint (el caso del endpoint necesita `httpx`).
