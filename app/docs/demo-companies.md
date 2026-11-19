# Empresas ficticias de demostración

Veinte cuentas con el asistente completo (pasos 1 a 6) para enseñar la herramienta, comparar qué temas propone la IA frente a los que añade cada empresa y revisar informes largos sin rellenar nada a mano. Los datos son inventados; los nombres, cifras y LEI no corresponden a ninguna entidad real.

| Cuenta | Empresa | Actividad (NACE) |
|---|---|---|
| test1@airis.com | Panificadora Castellana, S.A. | C10.7.1 panadería industrial |
| test2@airis.com | Logística Ibérica del Ebro, S.L. | H49.4.1 transporte de mercancías |
| test3@airis.com | Hoteles Mediterráneo Azul, S.A. | I55.1.0 hoteles (grupo cotizado) |
| test4@airis.com | Componentes Eléctricos Norte, S.L. | C29.3.1 componentes de automoción |
| test5@airis.com | Energías Renovables del Sur, S.A. | D35.1.2 generación renovable |
| test6@airis.com | Construcciones Levantinas Puig, S.A. | F41.0.2 construcción de edificios no residenciales |
| test7@airis.com | Laboratorios Farmacéuticos Guadarrama, S.A. | C21.2.0 especialidades farmacéuticas (grupo cotizado) |
| test8@airis.com | Tejidos y Confección Atlántica, S.L. | C14.1.0 confección de prendas de punto |
| test9@airis.com | Química Fina del Vallès, S.A. | C20.1.4 química orgánica básica |
| test10@airis.com | Agropecuaria Tierras de Zamora, S.L. | A1.4.1 ganado bovino de leche |
| test11@airis.com | Conservas del Cantábrico Oriental, S.A. | C10.2.2 conservas de pescado |
| test12@airis.com | Nube Digital Soluciones, S.L. | K62.1.0 programación informática (grupo) |
| test13@airis.com | Seguros Mutua del Guadalquivir, S.A. | L65.1.2 seguros no vida |
| test14@airis.com | Supermercados Valle del Jerte, S.A. | G47.1.1 supermercados |
| test15@airis.com | Reciclajes y Valorización del Noroeste, S.L. | E38.2.1 valorización de residuos |
| test16@airis.com | Aceros Especiales del Nalón, S.A. | C24.1.0 acero (grupo cotizado) |
| test17@airis.com | Cementos del Alto Guadiana, S.A. | C23.5.1 cemento |
| test18@airis.com | Telecomunicaciones Rurales Ibéricas, S.A. | K61.1.0 telecomunicaciones (grupo) |
| test19@airis.com | Clínicas Salud Mediterránea, S.A. | R86.1.0 hospitales privados (grupo) |
| test20@airis.com | Terminal Portuaria del Estrecho, S.A. | H52.2.2 terminal portuaria |

Los perfiles están en `app/web/data/demo_companies.json`: encuesta inicial, preguntas de actividad, decisiones sobre la propuesta de la IA (aceptar, duda, rechazar y temas añadidos), acta del análisis de doble importancia, cifras etiquetables en XBRL con su tipo y unidad, los cinco datos «no aplica» como máximo y los documentos de evidencia. El resto de respuestas narrativas se generan de forma determinista a partir de la etiqueta de cada dato.

## Crear las cuentas y los datos

Desde `app/web`, en cualquier instalación (también en producción, donde el registro de cuentas puede estar cerrado):

```
php artisan i4s:demo:companies --password='una-contraseña-larga'
php artisan i4s:demo:companies --only=test3@airis.com --reset --password='...'
php artisan i4s:demo:companies --list
```

El comando crea la cuenta verificada (o reutiliza la existente), envía la encuesta inicial y espera la propuesta real del servicio de IA, registra la revisión del paso 2 con los temas añadidos, el acta, la confirmación de materialidad, todas las respuestas del paso 5 (con tipo, unidad y decimales en las cifras) y prepara y aprueba la versión del informe. Pasa por los mismos controladores que el navegador, así que cualquier regla de la plataforma se aplica igual. `--reset` borra los datos previos de la cuenta antes de regenerarlos; sin él, una cuenta con datos se deja intacta. La contraseña también puede venir de `DEMO_COMPANIES_PASSWORD`.

Las cuentas no son administradoras (el dominio `airis.com` no está en `ADMIN_EMAIL_DOMAINS`), por lo que aparecen en `/admin` como clientes normales y cuentan en el informe de utilidad de la IA.

## Tipos de dato según la taxonomía

Para que el informe XBRL etiquete el máximo de datos, el generador responde cada dato del paso 5 con el tipo que exige su concepto ESRS (`data/esrs_concept_types_v1.json`, extraído del esquema del paquete EFRAG verificado): booleanos como Sí/No, porcentajes con `%`, importes en EUR, recuentos enteros, toneladas de CO2e, MWh, toneladas, m3, hectáreas, fechas y años. Los datos cuyo concepto es narrativo reciben un texto; los que no tienen concepto uno a uno, los de enumeración y los que exigen periodo futuro o dimensiones también quedan en texto y no se etiquetan. Cuando varios datos comparten un concepto (por ejemplo S1, S2 y S4, o SBM-1_03 y S1-6_02), solo uno puede llevar cifra: el candidato iXBRL rechaza dos datos distintos con hechos numéricos sobre el mismo concepto, así que los demás quedan en texto; los booleanos y narrativas compartidos reciben el mismo valor y se etiqueta el primero. Las cifras curadas de `figures`/`typed_figures` tienen prioridad.

## Vídeos del recorrido

`app/frontend/tests/e2e/demo-walkthrough.spec.ts` graba, con un navegador real, un recorrido por los seis pasos de cada cuenta ya generada y deja el vídeo en `app/web/storage/app/private/demo-videos/<correo>.webm`:

```
cd app/frontend
DEMO_COMPANIES_PASSWORD='...' corepack pnpm test:e2e tests/e2e/demo-walkthrough.spec.ts
DEMO_COMPANIES_ONLY=test1@airis.com,test2@airis.com DEMO_COMPANIES_PASSWORD='...' corepack pnpm test:e2e tests/e2e/demo-walkthrough.spec.ts
```

Los administradores ven el enlace «Vídeo del recorrido» en `/admin` para cada cuenta que tenga un vídeo en esa carpeta (`GET /api/admin/users/{id}/demo-video`). Para publicarlos en otra instalación basta con copiar los ficheros `.webm` a la misma carpeta del servidor; no se guardan en el repositorio.
