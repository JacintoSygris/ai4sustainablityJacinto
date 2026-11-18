<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportingFact;
use App\Models\User;
use App\Services\Report\ReportingFactProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->user = User::factory()->create();
    $this->e2Topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('projects completed answers as reviewed facts owned by the account', function () {
    $characterization = reportingFactProjectorCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => [
            'datapoint_id' => 'BP-1_01',
            'status' => 'completed',
            'value' => 'Prepared on a consolidated basis.',
            'evidence_reference' => 'Finance pack 2025',
            'note' => 'Controller review note.',
            'updated_at' => '2026-09-28T10:15:30.250Z',
        ],
    ]);

    $facts = app(ReportingFactProjector::class)->project($characterization);

    expect($facts)->toHaveCount(1)
        ->and($facts['BP-1_01']['fact_id'])->toBe(ReportingFact::factId($characterization->id, ReportingFact::PROFILE_ID, 'BP-1_01', [], 'text', 'und'))
        ->and($facts['BP-1_01']['applicability'])->toBe('applicable')
        ->and($facts['BP-1_01']['value'])->toBe(['text' => 'Prepared on a consolidated basis.'])
        ->and($facts['BP-1_01']['approval_status'])->toBe('reviewed')
        ->and($facts['BP-1_01']['provenance'])->toBe(ReportingFactProjector::PROVENANCE)
        ->and($facts['BP-1_01']['reviewed_by_user_id'])->toBe($this->user->id)
        ->and($facts['BP-1_01']['reviewed_at']->format('Y-m-d H:i:s.u'))->toBe('2026-09-28 10:15:30.000000')
        ->and($facts['BP-1_01']['evidence_refs'])->toBe([
            ['type' => 'evidence_reference', 'value' => 'Finance pack 2025'],
            ['type' => 'note', 'value' => 'Controller review note.'],
        ]);
});

it('projects drafts as pending and not applicable answers as justified nil facts', function () {
    $characterization = reportingFactProjectorCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => ['datapoint_id' => 'BP-1_01', 'status' => 'draft', 'value' => 'Partial.'],
        'BP-1_02' => ['datapoint_id' => 'BP-1_02', 'status' => 'not_applicable', 'note' => 'No subsidiaries.'],
        'BP-1_03' => ['datapoint_id' => 'BP-1_03', 'status' => 'not_applicable'],
        'BP-1_04' => ['datapoint_id' => 'BP-1_04', 'status' => 'completed'],
    ]);

    $facts = app(ReportingFactProjector::class)->project($characterization);

    expect($facts['BP-1_01'])->toMatchArray(['applicability' => 'pending', 'approval_status' => 'review_required', 'reviewed_at' => null, 'reviewed_by_user_id' => null]);
    expect($facts['BP-1_02'])->toMatchArray(['applicability' => 'not_applicable', 'value_type' => 'nil', 'nil' => true, 'nil_reason' => 'not_applicable', 'approval_status' => 'reviewed', 'blocking_reasons' => []]);
    expect($facts['BP-1_03']['blocking_reasons'])->toBe(['not_applicable_missing_justification']);
    // Completed without a value: decided, but there is nothing to report yet.
    expect($facts['BP-1_04'])->toMatchArray(['applicability' => 'applicable', 'value_type' => 'nil', 'nil' => true, 'nil_reason' => 'no_value']);
});

it('keeps typed numeric lexemes and applies type defaults', function () {
    $characterization = reportingFactProjectorCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => ['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'value' => '9007199254740993', 'value_type' => 'integer'],
        'BP-1_02' => ['datapoint_id' => 'BP-1_02', 'status' => 'completed', 'value' => '12.5', 'value_type' => 'number', 'unit' => '%', 'decimals' => 1],
        'BP-1_03' => ['datapoint_id' => 'BP-1_03', 'status' => 'completed', 'value' => 'sí', 'value_type' => 'boolean'],
        'BP-1_04' => ['datapoint_id' => 'BP-1_04', 'status' => 'completed', 'value' => '2025-12-31', 'value_type' => 'date'],
    ]);

    $facts = app(ReportingFactProjector::class)->project($characterization);

    expect($facts['BP-1_01'])->toMatchArray(['value_type' => 'integer', 'value' => ['value' => '9007199254740993'], 'unit' => 'pure', 'decimals' => 0]);
    expect($facts['BP-1_02'])->toMatchArray(['value_type' => 'number', 'value' => ['value' => '12.5'], 'unit' => '%', 'decimals' => 1]);
    expect($facts['BP-1_03'])->toMatchArray(['value_type' => 'boolean', 'value' => ['value' => true], 'unit' => null]);
    expect($facts['BP-1_04'])->toMatchArray(['value_type' => 'date', 'value' => ['value' => '2025-12-31']]);
});

it('ignores malformed and orphaned responses safely', function () {
    $characterization = reportingFactProjectorCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => 'not-an-array',
        'ZZ-9_99' => ['datapoint_id' => 'ZZ-9_99', 'status' => 'completed', 'value' => 'Outside the corpus.'],
        'BP-1_02' => ['datapoint_id' => 'BP-1_02', 'status' => 'weird'],
    ]);

    expect(app(ReportingFactProjector::class)->project($characterization))->toBe([]);
});

it('suggests the fact type from the inventory data type', function (?string $dataType, string $expected) {
    expect(ReportingFactProjector::suggestedValueType($dataType))->toBe($expected);
})->with([
    [null, 'text'],
    ['narrative', 'text'],
    ['semi-narrative', 'text'],
    ['Table/Narrative', 'text'],
    ['monetary', 'monetary'],
    ['Monerary', 'monetary'],
    ['Integer', 'integer'],
    ['Integer/percentage', 'integer'],
    ['percent', 'number'],
    ['Table/decimal', 'number'],
    ['ghgEmissions', 'number'],
    ['Mass', 'number'],
    ['gYear', 'date'],
    ['MDR-P', 'text'],
]);

function reportingFactProjectorCharacterization(User $user, EsrsTopic $topic, array $responses): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$topic->id],
        'form_data' => [
            'operations' => ['employee_count_range' => '50_249'],
            'materiality_confirmation' => ['confirmed_topic_ids' => [$topic->id]],
            'esrs_datapoint_responses' => ['schema_version' => 'v0', 'responses' => $responses],
        ],
    ]);
}
