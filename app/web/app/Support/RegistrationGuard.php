<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Public-signup abuse guard: honeypot (the per-IP rate limit lives in the
 * registration controller).
 *
 * Production registration is available only when email verification is
 * enforced and the verification mail can be delivered through a durable queue
 * and a mailer that does not expose the verification link.
 */
class RegistrationGuard
{
    public static function registrationAvailable(): bool
    {
        return self::registrationUnavailableReasons() === [];
    }

    /** @return list<string> Configuration names only; never include secret values. */
    public static function registrationUnavailableReasons(): array
    {
        $checks = [
            'AUTH_PUBLIC_REGISTRATION_ENABLED' => (bool) config('services.auth_hardening.public_registration_enabled'),
        ];

        if (! app()->environment('production')) {
            return $checks['AUTH_PUBLIC_REGISTRATION_ENABLED'] ? [] : ['AUTH_PUBLIC_REGISTRATION_ENABLED'];
        }

        $checks += [
            'AUTH_REQUIRE_EMAIL_VERIFICATION' => (bool) config('services.auth_hardening.require_email_verification'),
            'QUEUE_CONNECTION' => SensitiveDeliveryGuard::queueIsDurable(),
            'MAIL_MAILER' => SensitiveDeliveryGuard::mailerProtectsSecrets(),
            'APP_URL' => self::hasCanonicalOrigin(),
        ];

        return array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
    }

    /**
     * @return array<string, string> validation-style errors (empty = passed)
     */
    public static function check(Request $request): array
    {
        $errors = [];

        $honeypotField = (string) config('services.auth_hardening.honeypot_field');
        if ($honeypotField !== '' && filled($request->input($honeypotField))) {
            // A bot filled the hidden field. Return a generic error; never reveal
            // the honeypot to the client copy.
            $errors['email'] = __('No se ha podido completar el registro. Inténtalo de nuevo.');
        }

        return $errors;
    }

    private static function hasCanonicalOrigin(): bool
    {
        try {
            return CanonicalPublicUrl::root() !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
