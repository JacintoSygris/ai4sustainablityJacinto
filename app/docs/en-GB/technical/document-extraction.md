# Document Extraction

Back to the [technical manual](index.md).

## Current State

The AI service implements `POST /extract-document` (`app/ai-service/public_runtime/services/document_extraction.py`). It is off by default and only answers authenticated requests from Laravel.

- Laravel accepts PDF/DOCX up to 50 MB when `P6_DOCUMENT_UPLOAD_ENABLED=true` (it also needs the ClamAV scan configured, see `P6DocumentUploadGuard`).
- `ExtractCharacterizationDocumentJob` calls `POST {CHARACTERIZATION_API_BASE_URL}/extract-document` with the bearer token `CHARACTERIZATION_API_TOKEN`.
- The service reads the file from `document_path`, which must sit under one of the directories listed in `ESRS_UPLOAD_EXTRACT_BASE` (absolute paths separated by the OS path separator). Both processes must see the same filesystem path, so run them on the same host or share the storage volume.
- Text is extracted with `pypdf` (PDF, page numbers) or from `word/document.xml` (DOCX, pages follow rendered page breaks). Sentences are matched against the ESRS topic catalogue with TF-IDF (word 1–2-grams and character 3–5-grams). Negated sentences become `negative` evidence. Nothing is trained on user documents and nothing is logged beyond status codes.
- The catalogue is `esrs_topics.json` plus `ar16_to_python_esrs_mapping.json`: `web/data` in a checkout, or the directory in `I4S_DOCUMENT_EXTRACTION_CATALOGUE_DIR` (mount it read-only in the container image).

## Configuration

Python process:

```dotenv
I4S_DOCUMENT_EXTRACTION_ENABLED=true
I4S_AI_API_TOKEN=<same value as CHARACTERIZATION_API_TOKEN>
ESRS_UPLOAD_EXTRACT_BASE=/var/www/html/storage/app/private
I4S_DOCUMENT_EXTRACTION_CATALOGUE_DIR=/opt/i4s-catalogues   # only outside a checkout
```

Laravel: `P6_DOCUMENT_UPLOAD_ENABLED=true`, `P6_DOCUMENT_SCAN_ENABLED=true` with `clamscan`, and the timeouts `P6_DOCUMENT_EXTRACT_TIMEOUT` / `P6_DOCUMENT_EXTRACT_JOB_TIMEOUT`. The service spends at most 150 seconds per document and processes one document at a time (`503 extraction_busy` otherwise; the job retries once).

## Contract

Request:

```json
{
  "document_id": "123",
  "document_path": "/var/www/html/storage/app/private/characterization-documents/1/example.pdf",
  "original_filename": "example.pdf",
  "sha256": "<sha256 of the stored file>"
}
```

Response (validated strictly by the Laravel job):

```json
{
  "document_id": "123",
  "sha256": "<same hash>",
  "status": "ok",
  "parser_version": "v3",
  "config_version": "gate-run-4",
  "evidence": [
    {"standard": "E1", "topic_key": "esrs_e1_adaptation_to_climate_change", "kind": "positive",
     "confidence": 0.61, "page": 1, "snippet": "..."}
  ]
}
```

- `status`: `ok` (document `extracted`), `no_usable_evidence` (no text or no topic match) or `error` with a short `message` (`path_not_allowed`, `hash_mismatch`, `unsupported_format`, `unreadable_document`, `file_too_large`, `catalogue_unavailable`).
- At most 100 rows, 3 per topic and kind, snippets up to 300 characters, `topic_key` is the python ESRS key of an approved single-topic mapping.
- HTTP: 404 when disabled, 503 without token or busy, 401 with a wrong token, 413/422 for oversized or invalid bodies.

## Tests

`src/tests/test_document_extraction.py` covers DOCX and PDF parsing, the row contract, allowlist and hash failures, and the endpoint gating (the endpoint case needs `httpx`).
