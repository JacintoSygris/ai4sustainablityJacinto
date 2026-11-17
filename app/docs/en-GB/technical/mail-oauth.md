# Mail

Back to the [technical manual](index.md).

## SMTP

Mail is not optional if email verification or password reset is required. The repository includes Laravel SMTP configuration; the provider, credentials, SPF, DKIM, DMARC and reputation are provided by the operator.

Minimum variables:

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

Prerequisite: valid SMTP credentials in the secret manager.

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan config:clear
docker compose exec web php artisan optimize
```

Expected result: Laravel reloads configuration.

Check: request a password reset from `https://app.example.org/laravel/forgot-password` with an operator test account and confirm that the email arrives. Do not use private accounts or domains in documentation.

Common errors:

| Symptom | Check | Safe fix |
|---|---|---|
| Email does not arrive | Laravel logs and SMTP provider | Correct host, port, user or authorised sender. |
| Link uses wrong domain | `APP_URL` | Set `https://app.example.org` and clear cache. |
| TLS fails | Provider port/encryption | Use provider-documented mode; do not disable security globally. |
