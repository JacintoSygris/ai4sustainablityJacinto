# Correo

Volver al [manual tecnico](index.md).

## SMTP

El correo no es opcional si se exige verificacion de email o restablecimiento de contrasena. El repositorio incluye configuracion SMTP Laravel; el proveedor, credenciales, SPF, DKIM, DMARC y reputacion los aporta el operador.

Variables minimas:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.org
MAIL_PORT=587
MAIL_USERNAME=example-smtp-user
MAIL_PASSWORD=managed-secret
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@app.example.org
MAIL_FROM_NAME=IA4Sustainability
AUTH_REQUIRE_EMAIL_VERIFICATION=true
```

Precondicion: credenciales SMTP validas en el gestor de secretos.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan config:clear
docker compose exec web php artisan optimize
```

Resultado esperado: Laravel recarga configuracion.

Comprobacion: solicitar un restablecimiento de contrasena desde `https://app.example.org/laravel/forgot-password` con una cuenta de prueba del operador y confirmar que llega el correo. No usar cuentas ni dominios privados en documentacion.

Errores comunes:

| Sintoma | Comprobacion | Solucion segura |
|---|---|---|
| No llega email | Logs de Laravel y proveedor SMTP | Corregir host, puerto, usuario o remitente autorizado. |
| Enlace usa dominio incorrecto | `APP_URL` | Ajustar a `https://app.example.org` y limpiar cache. |
| TLS falla | Puerto/encryption del proveedor | Usar el modo documentado por el proveedor, no desactivar seguridad global. |
