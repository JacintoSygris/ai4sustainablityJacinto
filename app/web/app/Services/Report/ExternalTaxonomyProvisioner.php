<?php

namespace App\Services\Report;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Downloads the published EFRAG taxonomy package into the external asset root and
 * writes the manifest the report pipeline expects, so installations without shell
 * access to the server still get a verified taxonomy. The package is never stored
 * inside the application or storage trees and never committed. An operator-managed
 * manifest (ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH) is left untouched.
 */
class ExternalTaxonomyProvisioner
{
    private const LOCK_NAME = 'i4s:report:external-taxonomy-provision';

    public function __construct(private readonly ExternalTaxonomyManifestRepository $manifests) {}

    /**
     * @return array{state: 'verified'|'skipped'|'failed', reason?: string}
     */
    public function ensure(ReportingProfile $profile): array
    {
        if ($this->alreadyVerified($profile)) {
            return ['state' => 'verified'];
        }
        if (ExternalAssetRoot::manifestIsOperatorManaged()) {
            return ['state' => 'skipped', 'reason' => 'operator_managed_manifest'];
        }
        if (! (bool) config('ixbrl.external_taxonomy.autoprovision', true)) {
            return ['state' => 'skipped', 'reason' => 'autoprovision_disabled'];
        }
        if ($profile->profileId() !== (string) config('ixbrl.external_taxonomy.profile_id')) {
            return ['state' => 'skipped', 'reason' => 'profile_not_provisionable'];
        }

        $lock = Cache::lock(self::LOCK_NAME, 300);
        if (! $lock->get()) {
            return ['state' => 'failed', 'reason' => 'external_taxonomy_provisioning_busy'];
        }

        try {
            if ($this->alreadyVerified($profile)) {
                return ['state' => 'verified'];
            }

            return $this->provision($profile);
        } finally {
            $lock->release();
        }
    }

    /** @return array{state: 'verified'|'failed', reason?: string} */
    private function provision(ReportingProfile $profile): array
    {
        $directory = ExternalAssetRoot::taxonomyDirectory();
        $manifestPath = ExternalAssetRoot::manifestPath();
        if ($directory === null || $manifestPath === null) {
            return ['state' => 'failed', 'reason' => 'external_root_unavailable'];
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            return ['state' => 'failed', 'reason' => 'external_root_unavailable'];
        }

        $expectedChecksum = strtolower(trim((string) config('ixbrl.external_taxonomy.checksum')));
        $url = (string) config('ixbrl.external_taxonomy.download_url');
        $packagePath = $directory.'/'.basename((string) config('ixbrl.external_taxonomy.package_filename', 'ESRS-Set1-XBRL-Taxonomy.zip'));
        $maxBytes = max(1, (int) config('ixbrl.external_taxonomy.max_bytes', 50 * 1024 * 1024));
        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedChecksum) || ! str_starts_with($url, 'https://')) {
            return ['state' => 'failed', 'reason' => 'external_taxonomy_source_invalid'];
        }

        try {
            if (! is_file($packagePath) || strtolower((string) hash_file('sha256', $packagePath)) !== $expectedChecksum) {
                $this->download($url, $packagePath, $expectedChecksum, $maxBytes);
            }
            $this->writeManifest($manifestPath, $profile, $packagePath, $expectedChecksum);
            // Re-run the strict reader so the result reflects what the pipeline will see.
            $this->manifests->loadForProfile($profile);
        } catch (ConnectionException) {
            return ['state' => 'failed', 'reason' => 'external_taxonomy_download_failed'];
        } catch (RuntimeException $e) {
            return ['state' => 'failed', 'reason' => $e->getMessage()];
        } catch (Throwable) {
            return ['state' => 'failed', 'reason' => 'external_taxonomy_download_failed'];
        }

        return ['state' => 'verified'];
    }

    private function alreadyVerified(ReportingProfile $profile): bool
    {
        try {
            $this->manifests->loadForProfile($profile);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function download(string $url, string $packagePath, string $expectedChecksum, int $maxBytes): void
    {
        $response = Http::withOptions(['stream' => false])
            ->timeout(max(30, (int) config('ixbrl.external_taxonomy.timeout_seconds', 120)))
            ->connectTimeout(10)
            ->withHeaders(['Accept' => 'application/zip, application/octet-stream'])
            ->get($url);
        if (! $response->ok()) {
            throw new RuntimeException('external_taxonomy_download_failed');
        }
        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) > $maxBytes || ! str_starts_with($bytes, 'PK')) {
            throw new RuntimeException('external_taxonomy_download_failed');
        }
        if (strtolower(hash('sha256', $bytes)) !== $expectedChecksum) {
            throw new RuntimeException('external_taxonomy_package_checksum_mismatch');
        }
        $temporary = $packagePath.'.part-'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! @rename($temporary, $packagePath)) {
            @unlink($temporary);
            throw new RuntimeException('external_taxonomy_download_failed');
        }
        @chmod($packagePath, 0640);
    }

    private function writeManifest(string $manifestPath, ReportingProfile $profile, string $packagePath, string $checksum): void
    {
        $manifest = [
            'schema_version' => 'external_taxonomy_manifest_v1',
            'profile_id' => $profile->profileId(),
            'taxonomy_entrypoint' => $profile->taxonomy()['entrypoint'] ?? null,
            'taxonomy_package_path' => $packagePath,
            'taxonomy_package_checksum' => $checksum,
            'external_taxonomy_package_confirmed' => true,
            'provisioned_by' => 'esrs:provision-external-taxonomy',
            'provisioned_at' => now()->toIso8601String(),
            'source_url' => (string) config('ixbrl.external_taxonomy.download_url'),
        ];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $temporary = $manifestPath.'.part-'.bin2hex(random_bytes(6));
        if ($json === false || file_put_contents($temporary, $json."\n", LOCK_EX) === false || ! @rename($temporary, $manifestPath)) {
            @unlink($temporary);
            throw new RuntimeException('external_taxonomy_download_failed');
        }
        @chmod($manifestPath, 0640);
    }
}
