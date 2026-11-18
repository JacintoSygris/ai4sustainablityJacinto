# Interpretar resultados

Volver al [índice de documentación](../index.md).

La última parte del recorrido muestra un resumen de preparación y cobertura del trabajo ASG/ESRS. Debe leerse como un estado de trabajo para el informe de sostenibilidad, no como un informe ESRS desarrollado.

## Resumen de preparación

El resumen puede incluir identificación de la organización, ejercicio, código de actividad, asuntos materiales confirmados tras la doble materialidad, estados, contadores y limitaciones. Sirve para saber qué piezas están registradas y qué descargas pueden intentarse.

## Descargas

- **Hoja de decisión**: selección de asuntos materiales y motivos guardados.
- **Lista de indicadores/datos CSV**: elementos del inventario ESRS seleccionados por la aplicación.
- **Respuestas CSV**: respuestas estructuradas y referencias introducidas.
- **Resumen JSON**: estado de preparación, cobertura y trazabilidad técnica.
- **Paquete HTML**: resumen imprimible de preparación, temas y cobertura.
- **Informe XBRL, informe DOCX y paquete de evidencias**: salidas de la versión aprobada que contienen las respuestas completadas en el paso 5 (con su tipo y unidad). El informe XBRL es un documento Inline XBRL con el mismo diseño que el resto de salidas: portada con empresa, periodo y LEI, capítulos y requisitos de información con su código ESRS, y una tarjeta por dato con código, pregunta y respuesta. Las cifras llevan la etiqueta `ix:nonFraction` (la unidad `pure` solo viaja en la etiqueta, no se muestra) y las narrativas cuyo concepto admite texto llevan `ix:nonNumeric`. Se puede abrir en el navegador o descargar; las referencias de evidencia no se imprimen.
- **Candidato XHTML/iXBRL**: salida técnica protegida, no ofrecida como descarga ordinaria. Requiere snapshot aprobado, datos compatibles, LEI, taxonomía EFRAG externa y validación Arelle.

## Respuestas y versión del informe

Cada respuesta completada en el paso 5 es contenido del informe: su valor, el tipo de dato (texto, número, entero, importe, sí/no o fecha), la unidad y los decimales se registran una sola vez, allí. En el paso 6 no se vuelve a introducir ni a revisar nada: se prepara una versión del informe, que congela esas respuestas, y se aprueba con una declaración. Si cambia la caracterización, una respuesta o el perfil de reporting, la versión deja de estar vigente y debe prepararse y aprobarse otra.

## Cómo leer "listo"

Una descarga del informe lista significa que existe una versión vigente y aprobada que permite generarla. No significa que el contenido sea completo, exacto, asegurado, legalmente suficiente ni aceptado por un regulador.
