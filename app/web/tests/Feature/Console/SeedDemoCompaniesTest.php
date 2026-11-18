<?php

use App\Models\Characterization;
use App\Models\ReportingFact;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\DemoCompanyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false, 'services.characterization.driver' => 'mock', 'services.characterization.mock_outcome' => 'success']);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
});

it('lists the fictitious companies of the catalogue', function () {
    $catalogue = DemoCompanyBuilder::catalogue();

    expect($catalogue['companies'])->toHaveCount(5);
    foreach ($catalogue['companies'] as $company) {
        expect($company['email'])->toEndWith('@airis.com')
            ->and(count($company['not_applicable']))->toBeLessThanOrEqual(5)
            ->and(strlen($company['lei']))->toBe(20);
    }

    $this->artisan('i4s:demo:companies', ['--list' => true])->expectsOutputToContain('test1@airis.com')->assertSuccessful();
});

it('builds a complete fictitious company through every step and can regenerate it', function () {
    $this->artisan('i4s:demo:companies', ['--only' => ['test2@airis.com'], '--password' => 'demo-secret-2026'])
        ->expectsOutputToContain('Paso 6')
        ->assertSuccessful();

    $user = User::where('email', 'test2@airis.com')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeTrue();
    $characterization = Characterization::forUser($user->id)->firstOrFail();
    expect($characterization->status)->toBe(Characterization::STATUS_COMPLETED);

    $review = data_get($characterization->form_data, 'materiality_proposal_review');
    expect($review['topic_actions'])->not->toBeEmpty()
        ->and($review['added_topic_ids'])->not->toBeEmpty();
    expect(data_get($characterization->form_data, 'materiality_confirmation.confirmed_topic_ids'))->not->toBeEmpty();
    expect(data_get($characterization->form_data, 'double_materiality_process.acta.participants'))->toContain('Gerencia');

    $responses = data_get($characterization->form_data, 'esrs_datapoint_responses.responses');
    $statuses = collect($responses)->countBy('status');
    expect($statuses->get('not_applicable', 0))->toBeLessThanOrEqual(5)
        ->and($statuses->get('completed', 0))->toBeGreaterThan(50)
        ->and($statuses->get('draft', 0))->toBe(0);
    expect(collect($responses)->filter(fn (array $r) => ($r['value_type'] ?? null) === 'integer')->count())->toBeGreaterThan(0);

    $facts = ReportingFact::where('characterization_id', $characterization->id);
    expect($facts->count())->toBe(count($responses))
        ->and($facts->clone()->where('approval_status', 'reviewed')->count())->toBe(count($responses));
    $snapshot = ReportSnapshot::where('characterization_id', $characterization->id)->with('approval')->firstOrFail();
    expect($snapshot->approval)->not->toBeNull();

    // Running again without --reset refuses to touch the account; with --reset it starts over.
    $this->artisan('i4s:demo:companies', ['--only' => ['test2@airis.com'], '--password' => 'demo-secret-2026'])->assertFailed();
    $this->artisan('i4s:demo:companies', ['--only' => ['test2@airis.com'], '--password' => 'demo-secret-2026', '--reset' => true])->assertSuccessful();
    expect(Characterization::forUser($user->id)->count())->toBe(1)
        ->and(Characterization::forUser($user->id)->first()->id)->not->toBe($characterization->id);
});
