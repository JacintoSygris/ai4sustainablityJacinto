<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Walkthrough videos recorded for demo accounts live outside the repository, in
 * storage/app/private/demo-videos/<email-slug>.webm; the operator copies them
 * there (see docs/demo-companies.md). Administrators download them per account.
 */
final class DemoVideoStore
{
    public static function directory(): string
    {
        return storage_path('app/private/demo-videos');
    }

    public static function slug(User $user): string
    {
        // Same rule as the Playwright recorder: "test1@airis.com" -> "test1-at-airis-com".
        return Str::slug(str_replace(['@', '.'], ['-at-', '-'], mb_strtolower((string) $user->email)));
    }

    public static function path(User $user): ?string
    {
        $path = self::directory().'/'.self::slug($user).'.webm';

        return is_file($path) ? $path : null;
    }

    public static function exists(User $user): bool
    {
        return self::path($user) !== null;
    }

    public static function downloadName(User $user): string
    {
        return 'recorrido-'.self::slug($user).'.webm';
    }
}
