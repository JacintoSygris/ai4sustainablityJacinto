<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Platform administrators are verified users whose email belongs to one of the
 * configured domains (services.admin.email_domains). There is no role table:
 * membership of the domain is the whole rule.
 */
final class PlatformAdmin
{
    public static function isAdmin(?User $user): bool
    {
        if ($user === null || ! $user->hasVerifiedEmail()) {
            return false;
        }

        $domain = strtolower(trim((string) strrchr((string) $user->email, '@')));

        return $domain !== '' && in_array(ltrim($domain, '@'), self::domains(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function domains(): array
    {
        $configured = config('services.admin.email_domains', []);
        $list = is_array($configured) ? $configured : explode(',', (string) $configured);

        return array_values(array_filter(array_map(
            fn ($domain) => strtolower(ltrim(trim((string) $domain), '@')),
            $list,
        )));
    }

    /**
     * The user whose data a read-only request shows: an administrator may pass
     * `as_user=<id>` to look at another account; everyone else only sees their own.
     */
    public static function viewedUserId(Request $request): int
    {
        $user = $request->user();
        $requested = $request->query('as_user');

        if ($requested === null || $requested === '' || ! self::isAdmin($user)) {
            return (int) $user->id;
        }

        if (! preg_match('/^[1-9][0-9]*$/', (string) $requested)) {
            throw new NotFoundHttpException();
        }

        if (! User::query()->whereKey((int) $requested)->exists()) {
            throw new NotFoundHttpException();
        }

        return (int) $requested;
    }

    public static function viewingAnotherUser(Request $request): bool
    {
        return self::viewedUserId($request) !== (int) $request->user()->id;
    }
}
