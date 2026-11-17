# Limites de despliegue

Volver al [manual tecnico](index.md).

Esta pagina se conserva como ruta puente. Los limites estan integrados en [indice y alcance](scope.md), [arquitectura](architecture.md) y [extraccion documental](document-extraction.md).

Resumen: el repositorio no entrega un entorno de produccion completo. El operador aporta proxy TLS, PostgreSQL, Redis, worker, secretos, correo, backups, observabilidad y recuperacion. La extraccion documental requiere el servicio de IA con `I4S_DOCUMENT_EXTRACTION_ENABLED=true` y ClamAV antes de activarse.
