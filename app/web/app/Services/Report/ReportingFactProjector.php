<?php

namespace App\Services\Report;

use App\Models\Characterization;
use App\Models\ReportingFact;
use App\Services\EsrsDatapointCorpusBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Projects the step 5 datapoint responses into the report facts the outputs
 * consume. A response decided in step 5 is the reviewed fact: there is no
 * separate re-entry or one-by-one review in step 6 any more.
 */
class ReportingFactProjector
{
    public const PROVENANCE = 'p9_response';

    public const VALUE_TYPES = ['text', 'number', 'integer', 'monetary', 'boolean', 'date'];

    public const NUMERIC_VALUE_TYPES = ['number', 'integer', 'monetary'];

    public function __construct(
        private readonly EsrsDatapointCorpusBuilder $corpusBuilder,
    ) {}

    /**
     * Desired fact rows (without ids) for the characterization, keyed by datapoint id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function project(Characterization $characterization, ?array $corpus = null): array
    {
        $stored = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses.responses', []);
        if (! is_array($stored)) {
            return [];
        }

        $allowedDatapointIds = array_flip($this->corpusDatapointIds($corpus ?? $this->corpusBuilder->build($characterization)));
        $facts = [];

        foreach ($stored as $response) {
            if (! is_array($response)) {
                continue;
            }
            $datapointId = trim((string) ($response['datapoint_id'] ?? ''));
            $status = $response['status'] ?? null;
            if ($datapointId === '' || ! isset($allowedDatapointIds[$datapointId])) {
                continue;
            }
            if (! in_array($status, ['completed', 'draft', 'not_applicable'], true)) {
                continue;
            }
            $facts[$datapointId] = $this->projectResponse($characterization, $datapointId, $response, $status);
        }

        ksort($facts);

        return $facts;
    }

    /**
     * Kept for callers that only need the list shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function projectLegacy(Characterization $characterization): array
    {
        return array_values($this->project($characterization));
    }

    /**
     * Suggests the fact value type from the inventory data type (IG-3 wording).
     */
    public static function suggestedValueType(?string $dataType): string
    {
        $normalized = strtolower(trim((string) $dataType));
        if ($normalized === '') {
            return 'text';
        }
        if (str_contains($normalized, 'narrative')) {
            return 'text';
        }
        if (str_contains($normalized, 'mone')) {
            return 'monetary';
        }
        if (str_contains($normalized, 'integer')) {
            return 'integer';
        }
        if (str_contains($normalized, 'gyear') || str_contains($normalized, 'date')) {
            return 'date';
        }
        if (preg_match('/percent|decimal|numerical|mass|volume|area|energy|ghg|intensity/', $normalized) === 1) {
            return 'number';
        }

        return 'text';
    }

    /**
     * Default unit for a value type when the person gave none.
     */
    public static function defaultUnit(string $valueType, ?string $dataType = null): ?string
    {
        $normalized = strtolower(trim((string) $dataType));

        return match ($valueType) {
            'monetary' => 'EUR',
            'integer' => 'pure',
            'number' => str_contains($normalized, 'percent') ? '%' : 'pure',
            default => null,
        };
    }

    public static function defaultDecimals(string $valueType): ?int
    {
        return match ($valueType) {
            'integer' => 0,
            'monetary' => 2,
            'number' => 2,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function projectResponse(Characterization $characterization, string $datapointId, array $response, string $status): array
    {
        $text = $this->filledString($response['value'] ?? null);
        $requestedType = $this->filledString($response['value_type'] ?? null);
        $valueType = in_array($requestedType, self::VALUE_TYPES, true) ? $requestedType : 'text';
        $evidenceRefs = $this->evidenceRefs($response);
        $applicability = match ($status) {
            'completed' => 'applicable',
            'draft' => 'pending',
            'not_applicable' => 'not_applicable',
        };

        $value = null;
        $nil = true;
        $nilReason = null;
        $unit = null;
        $decimals = null;
        $language = null;
        $blockingReasons = [];

        if ($text !== null && $status !== 'not_applicable') {
            $typed = $this->typedValue($valueType, $text);
            if ($typed === null) {
                // The lexeme does not match the declared type: keep it readable as text
                // instead of silently dropping the person's answer.
                $valueType = 'text';
                $typed = ['text' => $text];
                $blockingReasons[] = 'value_does_not_match_type';
            }
            $value = $typed;
            $nil = false;
            if ($valueType === 'text') {
                // The answer's language is not recorded; a request-dependent guess would
                // change the fact identity between locales and mark approved versions stale.
                $language = 'und';
            }
            if (in_array($valueType, self::NUMERIC_VALUE_TYPES, true)) {
                $unit = $this->filledString($response['unit'] ?? null) ?? self::defaultUnit($valueType);
                $decimals = is_numeric($response['decimals'] ?? null) ? (int) $response['decimals'] : self::defaultDecimals($valueType);
            }
        } else {
            $valueType = 'nil';
            $nilReason = $status === 'not_applicable' ? 'not_applicable' : 'no_value';
        }

        if ($status === 'not_applicable' && $evidenceRefs === []) {
            $blockingReasons[] = 'not_applicable_missing_justification';
        }

        $updatedAt = $this->filledString($response['updated_at'] ?? null);
        try {
            // Database datetimes keep seconds only: normalise so re-syncs stay idempotent.
            $reviewedAt = $updatedAt !== null ? Carbon::parse($updatedAt)->utc()->startOfSecond() : null;
        } catch (\Throwable) {
            $reviewedAt = null;
        }

        return [
            'fact_id' => ReportingFact::factId(
                $characterization->id,
                ReportingFact::PROFILE_ID,
                $datapointId,
                [],
                $valueType,
                $language,
            ),
            'schema_version' => ReportingFact::SCHEMA_VERSION,
            'profile_id' => ReportingFact::PROFILE_ID,
            'datapoint_id' => $datapointId,
            'applicability' => $applicability,
            'value_type' => $valueType,
            'value' => $value,
            'unit' => $unit,
            'decimals' => $decimals,
            'dimensions' => [],
            'language' => $language,
            'nil' => $nil,
            'nil_reason' => $nilReason,
            'evidence_refs' => $evidenceRefs,
            'provenance' => self::PROVENANCE,
            // A decided step 5 answer is the review: drafts stay pending.
            'approval_status' => $status === 'draft' ? 'review_required' : 'reviewed',
            'blocking_reasons' => $blockingReasons,
            'reviewed_at' => $status === 'draft' ? null : $reviewedAt,
            'reviewed_by_user_id' => $status === 'draft' ? null : $characterization->user_id,
            'review_declaration_sha256' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function typedValue(string $valueType, string $text): ?array
    {
        $normalized = trim($text);

        return match ($valueType) {
            'text' => ['text' => $text],
            'number', 'monetary' => preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/', $normalized) === 1
                ? ['value' => $normalized]
                : null,
            'integer' => preg_match('/\A[+-]?\d+\z/', $normalized) === 1 ? ['value' => $normalized] : null,
            'boolean' => in_array(strtolower($normalized), ['true', 'false', '1', '0', 'sí', 'si', 'no', 'yes'], true)
                ? ['value' => in_array(strtolower($normalized), ['true', '1', 'sí', 'si', 'yes'], true)]
                : null,
            'date' => preg_match('/\A\d{4}(?:-\d{2}(?:-\d{2})?)?\z/', $normalized) === 1 ? ['value' => $normalized] : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, string>>
     */
    private function evidenceRefs(array $response): array
    {
        $refs = [];
        foreach (['evidence_reference' => 'evidence_reference', 'note' => 'note'] as $field => $type) {
            $value = $this->filledString($response[$field] ?? null);
            if ($value !== null) {
                $refs[] = ['type' => $type, 'value' => $value];
            }
        }

        return $refs;
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<int, string>
     */
    private function corpusDatapointIds(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block) => $block['datapoints'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values()
            ->all();
    }
}
