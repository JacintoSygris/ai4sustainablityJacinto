# Configuracion

Volver al [manual tecnico](index.md).

Esta pagina se conserva como ruta puente. La configuracion completa esta en [secretos y parametros](secrets-and-parameters.md), con persistencia en [base de datos y persistencia](database-persistence.md) y despliegue en [servicios y despliegue](services-deployment.md).

Regla principal: una variable configurada no convierte una capacidad ausente en funcional. En particular, `P6_DOCUMENT_UPLOAD_ENABLED` solo funciona si el servicio de IA se ejecuta con `I4S_DOCUMENT_EXTRACTION_ENABLED=true` y un `ESRS_UPLOAD_EXTRACT_BASE` válido (ver [document-extraction.md](document-extraction.md)).
