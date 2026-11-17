<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\Cache;

class ArelleXhtmlIxbrlValidator
{
    private const TIMEOUT_SECONDS = 120;

    // The EFRAG taxonomy emits one assertion message per untagged "always disclose" element (several
    // hundred, ~1.5 MB of JSON log for a small report), so the budget must hold the whole log.
    private const MAX_OUTPUT_BYTES = 8388608;

    private const LOCK_NAME = 'i4s:report:arelle-validation';

    private readonly int $maxOutputBytes;

    public function __construct(
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
        ?int $maxOutputBytes = null,
    ) {
        $configured = config('services.report.arelle_max_output_bytes');
        $this->maxOutputBytes = $maxOutputBytes
            ?? (is_numeric($configured) && (int) $configured > 0 ? (int) $configured : self::MAX_OUTPUT_BYTES);
    }

    /**
     * @param array<string, mixed> $internalManifest
     */
    /**
     * Validates the candidate with Arelle. Structural problems (XHTML schema, XBRL 2.1, Inline XBRL,
     * formula processing) block the candidate. The taxonomy's own assertion messages (`message:*`
     * codes, e.g. EFRAG's "always disclose" completeness checks) do not block: they describe what
     * the report still lacks and are returned so callers can surface them.
     *
     * @return array{assertion_errors: int, assertion_warnings: int}
     */
    public function validate(string $xhtml, ReportingProfile $profile, array $internalManifest, bool $reportPackage = false): array
    {
        $prefix = ArelleCommandLocator::resolve();
        if (! is_array($prefix) || ! array_is_list($prefix) || $prefix === []) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_unavailable');
        }
        foreach ($prefix as $token) {
            if (! is_string($token) || $token === '' || str_contains($token, "\0")) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_unavailable');
            }
        }

        $executable = $prefix[0];
        $isAbsolute = DIRECTORY_SEPARATOR === '\\'
            ? preg_match('~^[A-Za-z]:[/\\\\]~', $executable) === 1
            : str_starts_with($executable, '/') && ! str_starts_with($executable, '//');
        if (! $isAbsolute || ! is_file($executable) || ! is_executable($executable)) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_unavailable');
        }

        $packagePath = $internalManifest['taxonomy_package_path'] ?? null;
        if (! is_string($packagePath) || $packagePath === '') {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
        }

        $lock = Cache::lock(self::LOCK_NAME, max(10, $this->timeoutSeconds + 10));
        if (! $lock->get()) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_busy');
        }

        try {
            $tmpDir = $this->temporaryDirectory();
            $xhtmlPath = $tmpDir.($reportPackage ? '/candidate.zip' : '/candidate.xhtml');
            $logPath = $tmpDir.'/validation.json';

            try {
                file_put_contents($xhtmlPath, $xhtml, LOCK_EX);
                chmod($xhtmlPath, 0600);

                $args = [
                    ...$prefix,
                    ...$this->profileArguments($profile),
                    '--validationExitCode',
                    '--disablePersistentConfig',
                    '--xdgConfigHome', $tmpDir,
                    '--logFile', $logPath,
                    '--packages',
                    $packagePath,
                    '--file',
                    $xhtmlPath,
                ];

                [$args, $bootstrapMarker] = $this->withCache($args);
                $result = $this->run($args, $tmpDir);
                $summary = $this->analyzeLog($logPath);
                if ($bootstrapMarker !== null && $summary !== null) {
                    @file_put_contents($bootstrapMarker, date('c')."\n");
                }
                // `--validationExitCode` also returns non-zero for assertion messages alone; a non-zero
                // exit without any recorded assertion error is an unexplained failure.
                if ($summary === null || ($result['exit_code'] !== 0 && $summary['assertion_errors'] === 0)) {
                    throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
                }

                return $summary;
            } finally {
                if (is_file($logPath)) @unlink($logPath);
                if (is_file($xhtmlPath)) {
                    @unlink($xhtmlPath);
                }
                if (is_dir($tmpDir)) {
                    @rmdir($tmpDir);
                }
            }
        } finally {
            $lock->release();
        }
    }

    /** True when the cache directory exists and the one-off online bootstrap has run. */
    public static function cacheWarm(): bool
    {
        $cache = ExternalAssetRoot::arelleCacheDirectory();

        return $cache === null || is_file($cache.'/.i4s-bootstrapped');
    }

    /**
     * Loads the taxonomy entry point through the external package with network access so
     * the schemas it imports land in the cache. No report is involved; the marker is written
     * when Arelle finishes loading, so later validations can stay offline.
     */
    public function warmCache(ReportingProfile $profile): bool
    {
        $prefix = ArelleCommandLocator::resolve();
        $cache = ExternalAssetRoot::arelleCacheDirectory();
        if ($prefix === null || $cache === null || self::cacheWarm()) {
            return false;
        }
        if (! is_dir($cache) && ! @mkdir($cache, 0750, true) && ! is_dir($cache)) {
            return false;
        }
        try {
            $manifest = app(ExternalTaxonomyManifestRepository::class)->loadInternalForProfile($profile);
        } catch (\RuntimeException) {
            return false;
        }
        $entrypoint = $profile->taxonomy()['entrypoint'] ?? null;
        if (! is_string($entrypoint) || $entrypoint === '') {
            return false;
        }

        $lock = Cache::lock(self::LOCK_NAME, max(10, $this->timeoutSeconds + 10));
        if (! $lock->get()) {
            return false;
        }
        try {
            $tmpDir = $this->temporaryDirectory();
            $logPath = $tmpDir.'/warmup.json';
            try {
                $args = [
                    ...$prefix,
                    '--internetConnectivity=online',
                    '--disablePersistentConfig',
                    '--xdgConfigHome', $tmpDir,
                    '--logFile', $logPath,
                    '--cacheDirectory', $cache,
                    '--packages', $manifest['taxonomy_package_path'],
                    '--file', $entrypoint,
                ];
                $result = $this->run($args, $tmpDir);
                $loaded = $result['exit_code'] === 0 || (is_file($logPath) && filesize($logPath) > 0);
                if ($loaded) {
                    @file_put_contents($cache.'/.i4s-bootstrapped', date('c')."\n");
                }

                return $loaded;
            } catch (XhtmlIxbrlCandidateException) {
                return false;
            } finally {
                if (is_file($logPath)) @unlink($logPath);
                if (is_dir($tmpDir)) @rmdir($tmpDir);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Adds the Arelle cache directory. The profile validates offline, so the first run
     * against an empty cache goes online once to fetch the schemas the EFRAG package
     * imports (xbrl.org, w3.org); a marker file records the bootstrap and every later
     * run stays offline.
     *
     * @param  list<string>  $args
     * @return array{0: list<string>, 1: ?string}
     */
    private function withCache(array $args): array
    {
        $cache = ExternalAssetRoot::arelleCacheDirectory();
        if ($cache === null) {
            return [$args, null];
        }
        if (! is_dir($cache) && ! @mkdir($cache, 0750, true) && ! is_dir($cache)) {
            return [$args, null];
        }
        $args = [...$args, '--cacheDirectory', $cache];
        $marker = $cache.'/.i4s-bootstrapped';
        if (is_file($marker) || ! (bool) config('ixbrl.arelle_cache_bootstrap', true)) {
            return [$args, null];
        }
        $args = array_map(
            static fn (string $arg): string => $arg === '--internetConnectivity=offline' ? '--internetConnectivity=online' : $arg,
            $args,
        );

        return [$args, $marker];
    }

    /** @return list<string> */
    private function profileArguments(ReportingProfile $profile): array
    {
        $args = $profile->validationConfig()['arelle']['arguments'] ?? [];

        return is_array($args) ? array_values(array_filter($args, 'is_string')) : [];
    }

    private function temporaryDirectory(): string
    {
        $base = rtrim(sys_get_temp_dir(), '/').'/i4s-arelle-'.bin2hex(random_bytes(12));
        if (! mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
        }
        chmod($base, 0700);

        return $base;
    }

    /**
     * @param list<string> $args
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function run(array $args, string $tmpDir): array
    {
        // Precreate the structured log so it shares the live aggregate output budget.
        $paths = [$tmpDir.'/stdout.capture', $tmpDir.'/stderr.capture', $tmpDir.'/validation.json'];
        $captures = [];
        $createdPaths = [];
        $pipes = [];
        $process = null;
        $limit = max(1, $this->maxOutputBytes);
        $deadline = hrtime(true) / 1e9 + max(1, $this->timeoutSeconds);

        try {
            foreach ($paths as $path) {
                $capture = @fopen($path, 'x+b');
                if ($capture === false) {
                    throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
                }
                $captures[] = $capture;
                $createdPaths[] = $path;
                if (! @chmod($path, 0600)) {
                    throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
                }
            }

            $descriptor = [0 => ['pipe', 'r'], 1 => $captures[0], 2 => $captures[1]];
            if (hrtime(true) / 1e9 >= $deadline) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
            }
            $process = @proc_open($args, $descriptor, $pipes, null, [
                'PATH' => '/usr/local/bin:/usr/bin:/bin',
                'LANG' => 'C.UTF-8',
                'TMPDIR' => $tmpDir,
                'PYTHONDONTWRITEBYTECODE' => '1',
            ], ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
            }
            fclose($pipes[0]);
            unset($pipes[0]);

            while (true) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
                }
                $status = @proc_get_status($process);
                if (! is_array($status) || ! is_bool($status['running'] ?? null) || ! is_int($status['exitcode'] ?? null)) {
                    throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
                }
                $total = 0;
                foreach ($paths as $path) {
                    clearstatcache(true, $path);
                    $size = @filesize($path);
                    if ($size === false || $size < 0 || $size > $limit - $total) {
                        throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
                    }
                    $total += $size;
                }
                // Check before accepting even a zero exit: a late child is a failure.
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
                }
                if (! $status['running']) {
                    if ($status['exitcode'] < 0) {
                        throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
                    }
                    $exitCode = $status['exitcode'];
                    break;
                }
                usleep(10000);
            }

            @proc_close($process);
            $process = null;
            $stdout = '';
            $stderr = '';
            if (hrtime(true) / 1e9 >= $deadline || ! $this->readProcessOutput(array_slice($paths, 0, 2), $stdout, $stderr)
                || hrtime(true) / 1e9 >= $deadline) {
                throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
            }

            return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        } catch (\Throwable) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
        } finally {
            try {
                if (is_resource($process)) {
                    try {
                        $this->terminate($process);
                    } finally {
                        @proc_close($process);
                    }
                }
            } finally {
                foreach (array_merge($pipes, $captures) as $handle) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
                foreach ($createdPaths as $path) {
                    // validate() reads the JSON after run() and owns its final cleanup.
                    if ($path !== $tmpDir.'/validation.json') @unlink($path);
                }
            }
        }
    }

    /** @param list<string> $paths */
    private function readProcessOutput(array $paths, string &$stdout, string &$stderr): bool
    {
        $limit = max(1, $this->maxOutputBytes);
        $chunks = [];
        $total = 0;
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            $size = @filesize($path);
            if ($size === false || $size < 0 || $size > $limit - $total) {
                return false;
            }
            $remaining = $limit - $total;
            $chunk = @file_get_contents($path, false, null, 0, $remaining + 1);
            if ($chunk === false || strlen($chunk) !== $size || strlen($chunk) > $remaining) {
                return false;
            }
            $chunks[] = $chunk;
            $total += strlen($chunk);
        }
        [$stdout, $stderr] = $chunks;

        return true;
    }

    /** @param resource $process */
    private function terminate($process): void
    {
        proc_terminate($process);
        usleep(100000);
        $status = proc_get_status($process);
        if ($status['running'] ?? false) {
            proc_terminate($process, 9);
        }
    }

    /**
     * Null when the log is missing, unanalyzable, lacks the "validated" line or records a
     * structural error; otherwise the counts of taxonomy assertion messages by severity.
     *
     * @return array{assertion_errors: int, assertion_warnings: int}|null
     */
    private function analyzeLog(string $path): ?array
    {
        if (! is_file($path) || filesize($path) > $this->maxOutputBytes) return null;
        try {
            $log = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['log'] ?? null;
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($log) || $log === []) return null;
        $validated = false;
        $assertionErrors = 0;
        $assertionWarnings = 0;
        foreach ($log as $entry) {
            if (! is_array($entry) || ! is_string($entry['level'] ?? null)) return null;
            $level = strtolower($entry['level']);
            $code = (string) ($entry['code'] ?? '');
            $message = $entry['message']['text'] ?? '';
            if (! is_string($message)) return null;
            $isAssertion = str_starts_with($code, 'message:');
            if ($isAssertion) {
                if ($level === 'error') $assertionErrors++;
                if ($level === 'warning') $assertionWarnings++;
                continue;
            }
            if (! in_array($level, ['info', 'warning', 'debug'], true)) return null;
            if ($code === 'info' && preg_match('/\bvalidated in \d+(?:[.,]\d+)? secs?\b/', $message)) $validated = true;
            if (str_starts_with($code, 'formula:') && str_contains(strtolower($message), 'unsatisfied')) return null;
        }

        return $validated ? ['assertion_errors' => $assertionErrors, 'assertion_warnings' => $assertionWarnings] : null;
    }
}
