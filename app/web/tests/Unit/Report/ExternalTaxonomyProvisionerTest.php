<?php

use App\Services\Report\ExternalAssetRoot;
use App\Services\Report\ExternalTaxonomyManifestRepository;
use App\Services\Report\ExternalTaxonomyProvisioner;
use App\Services\Report\ReportingProfileRepository;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/i4s-provisioner-tests/'.bin2hex(random_bytes(4));
    mkdir($this->root, 0700, true);
    $this->package = 'PK'.str_repeat('taxonomy ', 64);
    config([
        'services.report.external_root' => $this->root,
        'services.report.external_taxonomy_manifest_path' => null,
        'services.report.arelle_cache_directory' => null,
        'ixbrl.external_taxonomy.autoprovision' => true,
        'ixbrl.external_taxonomy.download_url' => 'https://taxonomy.example.test/ESRS-Set1-XBRL-Taxonomy.zip',
        'ixbrl.external_taxonomy.checksum' => hash('sha256', $this->package),
    ]);
    $this->profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');
});

afterEach(function () {
    foreach (glob($this->root.'/taxonomy/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->root.'/taxonomy');
    @rmdir($this->root);
});

function provisioner(): ExternalTaxonomyProvisioner
{
    return new ExternalTaxonomyProvisioner(new ExternalTaxonomyManifestRepository());
}

it('resolves the external root outside the application and storage trees', function () {
    expect(ExternalAssetRoot::root())->toBe($this->root)
        ->and(ExternalAssetRoot::manifestPath())->toBe($this->root.'/taxonomy/manifest.json')
        ->and(ExternalAssetRoot::arelleCacheDirectory())->toBe($this->root.'/arelle-cache');

    config(['services.report.external_root' => base_path('data')]);
    expect(ExternalAssetRoot::root())->toBeNull();

    config(['services.report.arelle_cache_directory' => 'off']);
    expect(ExternalAssetRoot::arelleCacheDirectory())->toBeNull();
});

it('downloads, verifies and registers the published package when no manifest exists', function () {
    Http::fake(['taxonomy.example.test/*' => Http::response($this->package, 200, ['Content-Type' => 'application/zip'])]);

    $result = provisioner()->ensure($this->profile);

    expect($result)->toBe(['state' => 'verified'])
        ->and(is_file($this->root.'/taxonomy/ESRS-Set1-XBRL-Taxonomy.zip'))->toBeTrue();
    $manifest = json_decode(file_get_contents($this->root.'/taxonomy/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['schema_version'])->toBe('external_taxonomy_manifest_v1')
        ->and($manifest['profile_id'])->toBe('esrs-2023-preparatory-v1')
        ->and($manifest['taxonomy_entrypoint'])->toBe('https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd')
        ->and($manifest['external_taxonomy_package_confirmed'])->toBeTrue();
    expect((new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile)['taxonomy_package_checksum'])->toBe(hash('sha256', $this->package));
    Http::assertSentCount(1);

    // A second call is a no-op once the manifest verifies.
    expect(provisioner()->ensure($this->profile))->toBe(['state' => 'verified']);
    Http::assertSentCount(1);
});

it('rejects a download whose checksum differs and leaves no files behind', function () {
    Http::fake(['taxonomy.example.test/*' => Http::response('PK tampered bytes', 200)]);

    expect(provisioner()->ensure($this->profile))->toBe(['state' => 'failed', 'reason' => 'external_taxonomy_package_checksum_mismatch'])
        ->and(is_file($this->root.'/taxonomy/ESRS-Set1-XBRL-Taxonomy.zip'))->toBeFalse()
        ->and(is_file($this->root.'/taxonomy/manifest.json'))->toBeFalse();
});

it('reports a failed download and keeps the taxonomy blocked', function () {
    Http::fake(['taxonomy.example.test/*' => Http::response('', 503)]);

    expect(provisioner()->ensure($this->profile))->toBe(['state' => 'failed', 'reason' => 'external_taxonomy_download_failed'])
        ->and(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))->toThrow(RuntimeException::class, 'external_taxonomy_manifest_missing');
});

it('never touches an operator-managed manifest nor downloads when auto-provisioning is off', function () {
    Http::fake();

    config(['services.report.external_taxonomy_manifest_path' => $this->root.'/custom-manifest.json']);
    expect(provisioner()->ensure($this->profile))->toBe(['state' => 'skipped', 'reason' => 'operator_managed_manifest']);

    config(['services.report.external_taxonomy_manifest_path' => null, 'ixbrl.external_taxonomy.autoprovision' => false]);
    expect(provisioner()->ensure($this->profile))->toBe(['state' => 'skipped', 'reason' => 'autoprovision_disabled']);
    Http::assertNothingSent();
});
