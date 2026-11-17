<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves the Arelle command line. ESRS_ARELLE_COMMAND wins; otherwise the usual
 * installation places are probed (the AI service virtual environment installs
 * arelle-release from its requirements, so a deployment without shell access still
 * gets a validator), and finally the running AI service is asked where its
 * interpreter lives.
 *
 * @return list<string>|null
 */
class ArelleCommandLocator
{
    private const CACHE_KEY = 'i4s:report:arelle-command';

    public static function resolve(): ?array
    {
        $configured = config('services.report.arelle_command');
        if (is_array($configured) && $configured !== []) {
            // Returned untouched: the validator rejects non-list or malformed configurations.
            return $configured;
        }
        if (is_string($configured) && trim($configured) !== '') {
            return [trim($configured)];
        }

        if (! (bool) config('services.report.arelle_probe', true)) {
            return null;
        }

        if ((bool) config('services.report.arelle_probe_local', true)) {
            foreach (self::candidates() as $candidate) {
                if (self::executable($candidate)) {
                    return [$candidate];
                }
            }
        }

        $remote = self::fromAiService();

        return $remote !== null ? [$remote] : null;
    }

    /** @return list<string> */
    public static function candidates(): array
    {
        $repository = dirname(base_path(), 2);
        $candidates = [
            $repository.'/ai-service/.venv/bin/arelleCmdLine',
            $repository.'/ai-service/.venv-learning/bin/arelleCmdLine',
            $repository.'/ai-service/venv/bin/arelleCmdLine',
        ];
        $root = ExternalAssetRoot::root();
        if ($root !== null) {
            $candidates[] = $root.'/arelle/bin/arelleCmdLine';
        }
        $candidates[] = '/opt/arelle/bin/arelleCmdLine';
        $candidates[] = '/usr/local/bin/arelleCmdLine';

        return $candidates;
    }

    private static function executable(string $path): bool
    {
        return str_starts_with($path, '/') && is_file($path) && is_executable($path);
    }

    private static function fromAiService(): ?string
    {
        $baseUrl = rtrim(trim((string) config('services.characterization.api.base_url')), '/');
        if ($baseUrl === '') {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && self::executable($cached)) {
            return $cached;
        }

        try {
            $response = Http::connectTimeout(3)->timeout(5)->acceptJson()->get($baseUrl.'/healthz');
            $command = $response->ok() ? $response->json('arelle.command') : null;
        } catch (Throwable) {
            return null;
        }
        if (! is_string($command) || ! self::executable($command)) {
            return null;
        }
        Cache::put(self::CACHE_KEY, $command, now()->addMinutes(10));

        return $command;
    }
}
