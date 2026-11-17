<?php

namespace App\Jobs;

use App\Services\Report\ArelleXhtmlIxbrlValidator;
use App\Services\Report\ReportingProfileRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Loads the EFRAG taxonomy once with network access so Arelle caches the schemas the
 * package imports; every later validation runs offline and well within the HTTP
 * proxy limits. Dispatched from the taxonomy status endpoint while the cache marker
 * is missing; the validator still bootstraps inline if the queue never runs it.
 */
final class WarmArelleCache implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(private readonly string $profileId = 'esrs-2023-preparatory-v1') {}

    public function uniqueId(): string
    {
        return 'arelle-cache:'.$this->profileId;
    }

    public function handle(ReportingProfileRepository $profiles, ArelleXhtmlIxbrlValidator $validator): void
    {
        $validator->warmCache($profiles->load($this->profileId));
    }
}
