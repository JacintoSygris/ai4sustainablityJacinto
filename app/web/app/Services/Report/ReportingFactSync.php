<?php

namespace App\Services\Report;

use App\Models\Characterization;
use App\Models\ReportingFact;
use Illuminate\Support\Carbon;

/**
 * Keeps the persisted report facts equal to the projection of the step 5
 * responses. Idempotent: an unchanged response never rewrites its fact row, so
 * approved report versions stay fresh until the person really changes a value.
 */
final class ReportingFactSync
{
    public function __construct(private readonly ReportingFactProjector $projector) {}

    /**
     * @return array{created: int, updated: int, deleted: int}
     */
    public function sync(Characterization $characterization, ?array $corpus = null): array
    {
        $desired = $this->projector->project($characterization, $corpus);
        $existing = ReportingFact::query()
            ->where('characterization_id', $characterization->id)
            ->get();

        $created = $updated = $deleted = 0;
        $keep = [];

        foreach ($desired as $datapointId => $attributes) {
            $current = $existing->first(fn (ReportingFact $fact) => $fact->fact_id === $attributes['fact_id']);
            if ($current === null) {
                $attributes['reviewed_at'] ??= $attributes['approval_status'] === 'reviewed' ? now()->utc()->startOfSecond() : null;
                $row = ReportingFact::create(['characterization_id' => $characterization->id] + $attributes);
                $created++;
            } else {
                // A missing response timestamp must not churn the review date.
                if ($attributes['reviewed_at'] === null && $attributes['approval_status'] === 'reviewed') {
                    $attributes['reviewed_at'] = $current->reviewed_at ?? now()->utc()->startOfSecond();
                }
                if ($this->differs($current, $attributes)) {
                    $current->forceFill($attributes)->save();
                    $updated++;
                }
                $row = $current;
            }
            $keep[$row->fact_id] = true;
        }

        // Every datapoint with a step 5 response is owned by the projection; other
        // rows of the same datapoint are superseded. Rows we created for datapoints
        // that no longer have a response disappear too.
        foreach ($existing as $fact) {
            if (isset($keep[$fact->fact_id])) {
                continue;
            }
            $owned = array_key_exists($fact->datapoint_id, $desired) || $fact->provenance === ReportingFactProjector::PROVENANCE;
            if ($owned) {
                $fact->delete();
                $deleted++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function differs(ReportingFact $fact, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            $current = $fact->{$key};
            if ($key === 'reviewed_at') {
                $current = $current instanceof Carbon ? $current->utc()->format('Y-m-d H:i:s') : $current;
                $value = $value instanceof Carbon ? $value->utc()->format('Y-m-d H:i:s') : $value;
            }
            if ($this->canonical($current) !== $this->canonical($value)) {
                return true;
            }
        }

        return false;
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
