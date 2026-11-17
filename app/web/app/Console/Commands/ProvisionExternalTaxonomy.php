<?php

namespace App\Console\Commands;

use App\Services\Report\ArelleCommandLocator;
use App\Services\Report\ExternalAssetRoot;
use App\Services\Report\ExternalTaxonomyProvisioner;
use App\Services\Report\ReportingProfileRepository;
use Illuminate\Console\Command;

class ProvisionExternalTaxonomy extends Command
{
    protected $signature = 'esrs:provision-external-taxonomy {--profile=esrs-2023-preparatory-v1 : Reporting profile}';

    protected $description = 'Download and verify the EFRAG taxonomy package into the external asset root and report the Arelle validator location';

    public function handle(ReportingProfileRepository $profiles, ExternalTaxonomyProvisioner $provisioner): int
    {
        $profile = $profiles->load((string) $this->option('profile'));
        $result = $provisioner->ensure($profile);

        $this->line('external root: '.(ExternalAssetRoot::root() ?? '(none writable)'));
        $this->line('manifest: '.(ExternalAssetRoot::manifestPath() ?? '(none)'));
        $this->line('taxonomy: '.$result['state'].(isset($result['reason']) ? ' ('.$result['reason'].')' : ''));
        $arelle = ArelleCommandLocator::resolve();
        $this->line('arelle: '.($arelle === null ? 'not found' : implode(' ', $arelle)));
        $this->line('arelle cache: '.(ExternalAssetRoot::arelleCacheDirectory() ?? '(disabled)'));

        return $result['state'] === 'verified' && $arelle !== null ? self::SUCCESS : self::FAILURE;
    }
}
