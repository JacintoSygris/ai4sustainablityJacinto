# Integración de autenticación

Volver al [índice de documentación](../index.md).

La autenticación usa sesión web de Laravel y protección CSRF. Las rutas protegidas requieren usuario autenticado y, si está activada, verificación de correo.

## Configuración pública

`GET /api/auth/register-config` informa de si el registro está disponible, de si se exige verificación de correo y del nombre del campo antibot. Esa información no contiene secretos.

## Registro e inicio de sesión

El cliente debe enviar los campos de registro, CSRF y el campo antibot vacío. No hay desafío de terceros: la protección antibots es el campo trampa y el límite de cinco altas por IP cada diez minutos. Los errores se tratan como validación de formulario.

## Verificación

Cuando la verificación está activada, las API protegidas pueden devolver HTTP 409 con `email_unverified`. El cliente debe llevar a la pantalla de verificación y permitir reenviar el correo.
