<?php

namespace App\Services\Report;

/**
 * Directory for operator assets that must live outside the application and storage
 * trees (the EFRAG taxonomy package, its manifest and the Arelle cache).
 *
 * ESRS_EXTERNAL_ROOT wins. Otherwise the first writable (or creatable) candidate is
 * used: a sibling of the repository checkout, the OS user's data directory, then the
 * system temporary directory. Nothing here ever points inside base_path() or storage_path().
 */
class ExternalAssetRoot
{
    public const DIRECTORY_NAME = 'i4s-external';

    public static function root(): ?string
    {
        $configured = trim((string) config('services.report.external_root'));
        if ($configured !== '') {
            return self::usable($configured) ? rtrim($configured, '/') : null;
        }

        foreach (self::candidates() as $candidate) {
            if (self::usable($candidate)) {
                return rtrim($candidate, '/');
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function candidates(): array
    {
        $candidates = [dirname(base_path(), 3).'/'.self::DIRECTORY_NAME];
        $home = getenv('HOME');
        if (is_string($home) && $home !== '' && $home !== '/') {
            $candidates[] = rtrim($home, '/').'/.local/share/'.self::DIRECTORY_NAME;
        }
        $candidates[] = rtrim(sys_get_temp_dir(), '/').'/'.self::DIRECTORY_NAME;

        return array_values(array_unique($candidates));
    }

    public static function taxonomyDirectory(): ?string
    {
        $root = self::root();

        return $root === null ? null : $root.'/taxonomy';
    }

    public static function manifestPath(): ?string
    {
        $configured = trim((string) config('services.report.external_taxonomy_manifest_path'));
        if ($configured !== '') {
            return $configured;
        }
        $directory = self::taxonomyDirectory();

        return $directory === null ? null : $directory.'/manifest.json';
    }

    /** True when the manifest location comes from configuration rather than the default root. */
    public static function manifestIsOperatorManaged(): bool
    {
        return trim((string) config('services.report.external_taxonomy_manifest_path')) !== '';
    }

    public static function arelleCacheDirectory(): ?string
    {
        $configured = config('services.report.arelle_cache_directory');
        if (is_string($configured)) {
            $trimmed = trim($configured);
            if (in_array(strtolower($trimmed), ['off', 'none', 'disabled'], true)) {
                return null;
            }
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
        $root = self::root();

        return $root === null ? null : $root.'/arelle-cache';
    }

    private static function usable(string $directory): bool
    {
        if (self::insideApplication($directory)) {
            return false;
        }
        if (is_dir($directory)) {
            return is_writable($directory);
        }
        $parent = dirname($directory);

        return is_dir($parent) && is_writable($parent);
    }

    private static function insideApplication(string $directory): bool
    {
        $normalized = rtrim(str_replace('\\', '/', $directory), '/');
        foreach ([base_path(), storage_path()] as $forbidden) {
            $root = realpath($forbidden);
            if ($root === false) {
                continue;
            }
            $root = rtrim(str_replace('\\', '/', $root), '/');
            if ($normalized === $root || str_starts_with($normalized, $root.'/')) {
                return true;
            }
        }

        return false;
    }
}
