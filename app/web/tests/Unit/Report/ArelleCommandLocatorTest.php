<?php

use App\Services\Report\ArelleCommandLocator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    config([
        'services.report.arelle_command' => null,
        'services.report.external_root' => '/nonexistent/i4s-external',
        'services.characterization.api.base_url' => null,
    ]);
    Cache::forget('i4s:report:arelle-command');
});

it('prefers the configured command and accepts argument lists', function () {
    config(['services.report.arelle_command' => '/opt/custom/arelleCmdLine']);
    expect(ArelleCommandLocator::resolve())->toBe(['/opt/custom/arelleCmdLine']);

    config(['services.report.arelle_command' => [PHP_BINARY, '-m', 'arelle']]);
    expect(ArelleCommandLocator::resolve())->toBe([PHP_BINARY, '-m', 'arelle']);
});

it('probes the AI service virtual environment and other usual locations', function () {
    $candidates = ArelleCommandLocator::candidates();
    expect($candidates[0])->toBe(dirname(base_path(), 2).'/ai-service/.venv/bin/arelleCmdLine')
        ->and($candidates)->toContain('/opt/arelle/bin/arelleCmdLine');
});

it('asks the running AI service where Arelle lives when nothing local is found', function () {
    // The test image may ship Arelle in /opt; the lookup under test is the remote one.
    config(['services.report.arelle_probe' => true, 'services.report.arelle_probe_local' => false]);
    $binary = sys_get_temp_dir().'/i4s-arelle-locator-'.bin2hex(random_bytes(4));
    file_put_contents($binary, "#!/bin/sh\nexit 0\n");
    chmod($binary, 0700);
    config(['services.characterization.api.base_url' => 'http://ai.example.test']);
    Http::fake(['ai.example.test/healthz' => Http::response(['status' => 'ok', 'arelle' => ['command' => $binary]])]);

    try {
        expect(ArelleCommandLocator::resolve())->toBe([$binary]);
        expect(ArelleCommandLocator::resolve())->toBe([$binary]);
        Http::assertSentCount(1);
    } finally {
        unlink($binary);
    }
});

it('returns null when the AI service reports no validator or is unreachable', function () {
    config(['services.report.arelle_probe' => true, 'services.report.arelle_probe_local' => false]);
    config(['services.characterization.api.base_url' => 'http://ai.example.test']);
    Http::fake(['ai.example.test/healthz' => Http::response(['status' => 'ok', 'arelle' => ['command' => null]])]);
    expect(ArelleCommandLocator::resolve())->toBeNull();

    Http::fake(['ai.example.test/healthz' => Http::response('', 500)]);
    expect(ArelleCommandLocator::resolve())->toBeNull();
});
