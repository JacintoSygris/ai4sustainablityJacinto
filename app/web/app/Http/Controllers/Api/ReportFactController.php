<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\ReportingFact;
use App\Services\PlatformAdmin;
use App\Services\Report\ReportingFactSync;
use Illuminate\Http\Request;

/**
 * Read-only view of the report facts. They are derived from the step 5 answers
 * (see ReportingFactSync); nothing is entered or reviewed by hand here.
 */
class ReportFactController extends Controller
{
    public function index(Request $request, ReportingFactSync $sync)
    {
        $characterization = Characterization::forUser(PlatformAdmin::viewedUserId($request))->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        $sync->sync($characterization);

        return response()->json(['data' => $this->state($characterization)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Characterization $characterization): array
    {
        $persistedFacts = ReportingFact::query()
            ->where('characterization_id', $characterization->id)
            ->orderBy('datapoint_id')
            ->orderBy('fact_id')
            ->get()
            ->map(fn (ReportingFact $fact) => $fact->toApiArray())
            ->values()
            ->all();

        return [
            'characterization_id' => $characterization->id,
            'schema_version' => ReportingFact::SCHEMA_VERSION,
            'profile_id' => ReportingFact::PROFILE_ID,
            'source' => 'esrs_datapoint_responses',
            'persisted_facts' => $persistedFacts,
            'persisted_fact_count' => count($persistedFacts),
            'reviewed_fact_count' => count(array_filter($persistedFacts, fn (array $fact) => $fact['approval_status'] === 'reviewed')),
            'claimable_fact_count' => count(array_filter(
                $persistedFacts,
                fn (array $fact) => $fact['approval_status'] === 'reviewed' && $fact['applicability'] === 'applicable' && ! $fact['nil'] && $fact['blocking_reasons'] === [],
            )),
        ];
    }
}
