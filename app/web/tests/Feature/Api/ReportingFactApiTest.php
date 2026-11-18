<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportingFact;
use App\Models\User;
use App\Services\Report\ReportingFactProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->e2Topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('requires authentication for reporting facts', function () {
    $this->getJson('/api/report/facts')->assertUnauthorized();
});

it('returns null when the current user has no characterization', function () {
    $this->actingAs($this->user)
        ->getJson('/api/report/facts')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('derives reviewed report facts from the decided step 5 answers on read', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => [
            'datapoint_id' => 'BP-1_01',
            'status' => 'completed',
            'value' => 'Prepared on a consolidated basis.',
            'evidence_reference' => 'Finance pack 2025',
            'note' => 'Controller review note.',
            'updated_at' => '2026-09-28T10:15:30Z',
        ],
        'BP-1_02' => [
            'datapoint_id' => 'BP-1_02',
            'status' => 'not_applicable',
            'note' => 'No subsidiaries.',
        ],
        'BP-1_03' => [
            'datapoint_id' => 'BP-1_03',
            'status' => 'draft',
            'value' => 'Still collecting.',
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/report/facts')
        ->assertOk()
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.source', 'esrs_datapoint_responses')
        ->assertJsonPath('data.persisted_fact_count', 3)
        ->assertJsonPath('data.reviewed_fact_count', 2)
        ->assertJsonPath('data.claimable_fact_count', 1)
        ->assertJsonMissingPath('data.pending_p9_suggestions')
        ->assertJsonMissingPath('data.legacy_projection');

    $facts = collect($response->json('data.persisted_facts'))->keyBy('datapoint_id');

    expect($facts['BP-1_01'])->toMatchArray([
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Prepared on a consolidated basis.'],
        'language' => 'und',
        'nil' => false,
        'provenance' => ReportingFactProjector::PROVENANCE,
        'approval_status' => 'reviewed',
        'blocking_reasons' => [],
    ]);
    expect($facts['BP-1_01']['evidence_refs'])->toBe([
        ['type' => 'evidence_reference', 'value' => 'Finance pack 2025'],
        ['type' => 'note', 'value' => 'Controller review note.'],
    ]);
    expect($facts['BP-1_02'])->toMatchArray([
        'applicability' => 'not_applicable',
        'value_type' => 'nil',
        'nil' => true,
        'nil_reason' => 'not_applicable',
        'approval_status' => 'reviewed',
        'evidence_refs' => [['type' => 'note', 'value' => 'No subsidiaries.']],
    ]);
    expect($facts['BP-1_03'])->toMatchArray([
        'applicability' => 'pending',
        'approval_status' => 'review_required',
    ]);

    $stored = ReportingFact::where('characterization_id', $characterization->id)->where('datapoint_id', 'BP-1_01')->sole();
    expect($stored->reviewed_by_user_id)->toBe($this->user->id);
    expect($stored->reviewed_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-28 10:15:30');
    expect($stored->fact_id)->toBe(ReportingFact::factId($characterization->id, ReportingFact::PROFILE_ID, 'BP-1_01', [], 'text', 'und'));
});

it('types numeric answers so the report and the iXBRL tagging never guess', function () {
    reportingFactApiCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => ['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'value' => '138', 'value_type' => 'integer', 'unit' => 'pure', 'decimals' => 0],
        'BP-1_02' => ['datapoint_id' => 'BP-1_02', 'status' => 'completed', 'value' => '18500000.50', 'value_type' => 'monetary'],
        'BP-1_03' => ['datapoint_id' => 'BP-1_03', 'status' => 'completed', 'value' => 'muchos', 'value_type' => 'integer'],
    ]);

    $facts = collect($this->actingAs($this->user)->getJson('/api/report/facts')->assertOk()->json('data.persisted_facts'))->keyBy('datapoint_id');

    expect($facts['BP-1_01'])->toMatchArray(['value_type' => 'integer', 'value' => ['value' => '138'], 'unit' => 'pure', 'decimals' => 0, 'language' => null]);
    // Missing unit and decimals fall back to the sensible defaults of the type.
    expect($facts['BP-1_02'])->toMatchArray(['value_type' => 'monetary', 'value' => ['value' => '18500000.50'], 'unit' => 'EUR', 'decimals' => 2]);
    // A stored answer that contradicts its type stays readable as text but is flagged.
    expect($facts['BP-1_03'])->toMatchArray(['value_type' => 'text', 'value' => ['text' => 'muchos'], 'blocking_reasons' => ['value_does_not_match_type']]);
});

it('keeps fact rows stable across repeated reads so approved versions stay fresh', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => ['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'value' => 'Stable answer.'],
    ]);

    $first = $this->actingAs($this->user)->getJson('/api/report/facts')->assertOk()->json('data.persisted_facts.0');
    $this->travel(5)->minutes();
    $second = $this->actingAs($this->user)->getJson('/api/report/facts')->assertOk()->json('data.persisted_facts.0');

    expect($second['id'])->toBe($first['id']);
    expect($second['updated_at'])->toBe($first['updated_at']);
    expect(ReportingFact::where('characterization_id', $characterization->id)->count())->toBe(1);
});

it('supersedes manual facts of answered datapoints and drops derived facts of removed answers', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => ['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'value' => 'Answered in step 5.'],
    ]);
    ReportingFact::create(reportingFactApiStoredFact($characterization, ['fact_id' => 'rf_manual_answered', 'datapoint_id' => 'BP-1_01']));
    ReportingFact::create(reportingFactApiStoredFact($characterization, ['fact_id' => 'rf_manual_unanswered', 'datapoint_id' => 'BP-1_02']));
    ReportingFact::create(reportingFactApiStoredFact($characterization, ['fact_id' => 'rf_derived_removed', 'datapoint_id' => 'BP-1_03', 'provenance' => ReportingFactProjector::PROVENANCE]));

    $facts = collect($this->actingAs($this->user)->getJson('/api/report/facts')->assertOk()->json('data.persisted_facts'));

    expect($facts->pluck('fact_id')->all())->not->toContain('rf_manual_answered', 'rf_derived_removed');
    expect($facts->where('datapoint_id', 'BP-1_01')->count())->toBe(1);
    expect($facts->where('datapoint_id', 'BP-1_01')->first()['provenance'])->toBe(ReportingFactProjector::PROVENANCE);
    // Rows nobody derived, for datapoints without an answer, are left alone.
    expect($facts->pluck('fact_id')->all())->toContain('rf_manual_unanswered');
});

it('no longer accepts manual fact submission or review', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => ['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'value' => 'Answered in step 5.'],
    ]);
    $fact = ReportingFact::create(reportingFactApiStoredFact($characterization));

    $this->actingAs($this->user)->putJson('/api/report/facts', ['facts' => []])->assertMethodNotAllowed();
    $this->actingAs($this->user)->postJson('/api/report/facts/'.$fact->id.'/review', ['review_declaration' => 'x'])->assertNotFound();
});

it('does not expose another users characterization or facts', function () {
    $other = User::factory()->create();
    $otherCharacterization = reportingFactApiCharacterization($other, $this->e2Topic, [
        'BP-1_01' => ['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'value' => 'Private answer.'],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/report/facts?as_user='.$other->id)
        ->assertOk()
        ->assertJsonPath('data', null);

    expect(ReportingFact::where('characterization_id', $otherCharacterization->id)->count())->toBe(0);
});

function reportingFactApiCharacterization(User $user, EsrsTopic $topic, array $responses = []): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '50_249',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$topic->id],
            ],
            'esrs_datapoint_responses' => [
                'schema_version' => 'v0',
                'responses' => $responses,
            ],
        ],
    ]);
}

function reportingFactApiStoredFact(Characterization $characterization, array $overrides = []): array
{
    return array_replace([
        'characterization_id' => $characterization->id,
        'fact_id' => 'rf_default_review',
        'schema_version' => ReportingFact::SCHEMA_VERSION,
        'profile_id' => ReportingFact::PROFILE_ID,
        'datapoint_id' => 'BP-1_01',
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Default review fact.'],
        'unit' => null,
        'decimals' => null,
        'dimensions' => [],
        'language' => 'en',
        'nil' => false,
        'nil_reason' => null,
        'evidence_refs' => [['type' => 'note', 'value' => 'Prepared by reporting owner.']],
        'provenance' => 'api',
        'approval_status' => 'review_required',
        'blocking_reasons' => [],
    ], $overrides);
}
