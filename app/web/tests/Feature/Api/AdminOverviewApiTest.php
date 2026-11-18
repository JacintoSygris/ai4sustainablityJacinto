<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Services\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);
    config(['services.admin.email_domains' => ['sygris.com']]);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->admin = User::factory()->create(['email' => 'ana@sygris.com']);
    $this->client = User::factory()->create(['email' => 'cliente@empresa.test', 'name' => 'Cliente Demo']);
    [$this->t1, $this->t2, $this->t3, $this->t4] = EsrsTopic::query()->orderBy('id')->limit(4)->get()->all();
});

function completedProposal(User $user, array $aiTopicIds, array $extra = []): Characterization
{
    return Characterization::factory()->create(array_merge([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'C25.62',
        'esrs_topic_ids' => $aiTopicIds,
        'form_data' => ['company_profile' => ['company_name' => 'Taller Demo SL']],
        'result_data' => [
            'status' => 'completed',
            'candidate_topics' => array_map(fn (int $id) => ['ar16_topic_id' => $id, 'suggested' => true], $aiTopicIds),
            'raw_prediction' => [],
        ],
        'submitted_at' => now()->subMinutes(2),
        'completed_at' => now()->subMinute(),
    ], $extra));
}

it('treats verified users of the configured email domains as administrators', function () {
    expect(PlatformAdmin::isAdmin($this->admin))->toBeTrue();
    expect(PlatformAdmin::isAdmin($this->client))->toBeFalse();

    $unverified = User::factory()->unverified()->create(['email' => 'nuevo@sygris.com']);
    expect(PlatformAdmin::isAdmin($unverified))->toBeFalse();

    $upper = User::factory()->create(['email' => 'MAYUS@SYGRIS.COM']);
    expect(PlatformAdmin::isAdmin($upper))->toBeTrue();

    $this->actingAs($this->admin)->getJson('/api/auth/session')->assertOk()->assertJsonPath('data.admin', true);
    $this->actingAs($this->client)->getJson('/api/auth/session')->assertOk()->assertJsonPath('data.admin', false);
});

it('hides the admin overview from ordinary users', function () {
    $this->actingAs($this->client)->getJson('/api/admin/users')->assertForbidden();
    $this->actingAs($this->client)->getJson('/api/admin/ai-usefulness')->assertForbidden();
    $guest = $this->getJson('/api/admin/users');
    expect(in_array($guest->getStatusCode(), [401, 403], true))->toBeTrue('guests must not read the overview, got '.$guest->getStatusCode().' '.$guest->getContent());
});

it('lists every account with its progress for administrators', function () {
    completedProposal($this->client, [$this->t1->id, $this->t2->id], [
        'form_data' => [
            'company_profile' => ['company_name' => 'Taller Demo SL'],
            'materiality_proposal_review' => [
                'revision' => 1,
                'topic_actions' => [(string) $this->t1->id => 'accepted', (string) $this->t2->id => 'rejected'],
                'added_topic_ids' => [],
                'reviewed_at' => now()->toJSON(),
            ],
            'materiality_confirmation' => ['confirmed_topic_ids' => [$this->t1->id]],
        ],
    ]);

    $response = $this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk();
    $users = collect($response->json('data.users'))->keyBy('id');

    expect($users)->toHaveCount(2);
    expect($users[$this->admin->id]['is_admin'])->toBeTrue();
    expect($users[$this->admin->id]['characterization'])->toBeNull();

    $client = $users[$this->client->id];
    expect($client['is_admin'])->toBeFalse();
    expect($client['characterization']['company_name'])->toBe('Taller Demo SL');
    expect($client['characterization']['status'])->toBe('completed');
    expect($client['progress']['proposal_completed'])->toBeTrue();
    expect($client['progress']['review_started'])->toBeTrue();
    expect($client['progress']['confirmed'])->toBeTrue();
    expect($client['progress']['acta_registered'])->toBeFalse();
    expect($client['progress']['snapshots'])->toBe(0);
    expect($response->json('data.admin_domains'))->toBe(['sygris.com']);
});

it('measures how many AI topics clients keep and how many they add', function () {
    // Client A: AI proposed t1..t3; accepted t1, t2, rejected t3, added t4 in step 2;
    // final selection keeps t1 and t4 (one AI topic dropped, one client topic kept).
    completedProposal($this->client, [$this->t1->id, $this->t2->id, $this->t3->id], [
        'esrs_topic_ids' => [$this->t1->id, $this->t2->id, $this->t3->id, $this->t4->id],
        'form_data' => [
            'company_profile' => ['company_name' => 'Taller Demo SL'],
            'materiality_proposal_review' => [
                'revision' => 1,
                'topic_actions' => [
                    (string) $this->t1->id => 'accepted',
                    (string) $this->t2->id => 'accepted',
                    (string) $this->t3->id => 'rejected',
                    (string) $this->t4->id => 'accepted',
                ],
                'added_topic_ids' => [$this->t4->id],
                'reviewed_at' => now()->toJSON(),
            ],
            'materiality_confirmation' => ['confirmed_topic_ids' => [$this->t1->id, $this->t4->id]],
        ],
    ]);
    // Client B: proposal without any review yet.
    $other = User::factory()->create(['email' => 'otra@empresa.test']);
    completedProposal($other, [$this->t1->id, $this->t2->id]);
    // A stale review (older than the proposal) must not count.
    $stale = User::factory()->create(['email' => 'stale@empresa.test']);
    completedProposal($stale, [$this->t1->id], [
        'form_data' => ['materiality_proposal_review' => ['topic_actions' => [(string) $this->t1->id => 'accepted'], 'reviewed_at' => now()->subDay()->toJSON()]],
    ]);

    $response = $this->actingAs($this->admin)->getJson('/api/admin/ai-usefulness')->assertOk();
    $summary = $response->json('data.summary');

    expect($summary['companies_with_proposal'])->toBe(3);
    expect($summary['companies_with_review'])->toBe(1);
    expect($summary['companies_with_confirmation'])->toBe(1);
    expect($summary['ai_recommended_total'])->toBe(6);
    expect($summary['review'])->toMatchArray([
        'ai_recommended_total' => 3,
        'accepted' => 2,
        'unsure' => 0,
        'rejected' => 1,
        'pending' => 0,
        'acceptance_rate' => 0.6667,
        'added_by_client' => 1,
    ]);
    expect($summary['final'])->toMatchArray([
        'ai_recommended_total' => 3,
        'confirmed_total' => 2,
        'ai_kept' => 1,
        'ai_discarded' => 2,
        'added_by_client' => 1,
        'precision' => 0.3333,
        'coverage' => 0.5,
    ]);
    expect(collect($summary['acceptance_distribution'])->pluck('count', 'key')->all())
        ->toBe(['0-25' => 0, '25-50' => 0, '50-75' => 1, '75-100' => 0]);
    expect(collect($response->json('data.top_added_topics'))->pluck('count', 'id')->all())->toBe([$this->t4->id => 2]);
    expect(collect($response->json('data.top_accepted_topics'))->pluck('count', 'id')->all())->toBe([$this->t1->id => 1, $this->t2->id => 1]);

    $companies = collect($response->json('data.companies'))->keyBy('user_id');
    expect($companies[$this->client->id]['review']['acceptance_rate'])->toBe(0.6667);
    expect($companies[$other->id]['review']['available'])->toBeFalse();
    expect($companies[$stale->id]['review']['available'])->toBeFalse();
    expect($companies[$this->client->id]['final']['added_by_client'])->toBe(1);
    expect(array_keys($companies[$this->client->id]['review']))->not->toContain('accepted_topic_ids');

    $pdf = $this->actingAs($this->admin)->get('/api/admin/ai-usefulness?format=pdf');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
    expect($pdf->headers->get('content-disposition'))->toContain('informe-utilidad-ia.pdf');

    $this->actingAs($this->admin)->get('/api/admin/ai-usefulness?format=html')
        ->assertOk()
        ->assertSee('Informe de utilidad de la IA')
        ->assertSee('Taller Demo SL');
});

it('lets administrators read another account through as_user while clients only see their own', function () {
    completedProposal($this->client, [$this->t1->id], ['form_data' => ['company_profile' => ['company_name' => 'Taller Demo SL']]]);

    $this->actingAs($this->admin)
        ->get('/characterization/summary?as_user='.$this->client->id)
        ->assertOk()
        ->assertSee('Taller Demo SL');

    $this->actingAs($this->admin)
        ->get('/characterization/summary?as_user=999999')
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->getJson('/api/report?as_user='.$this->client->id)
        ->assertOk()
        ->assertJsonPath('data.characterization_id', Characterization::forUser($this->client->id)->first()->id);

    // Non-admins cannot borrow another account: the parameter is ignored.
    $this->actingAs($this->client)
        ->getJson('/api/report?as_user='.$this->admin->id)
        ->assertOk()
        ->assertJsonPath('data.characterization_id', Characterization::forUser($this->client->id)->first()->id);

    $this->actingAs($this->admin)
        ->getJson('/api/report/snapshots?as_user='.$this->client->id)
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('serves a stored walkthrough video to administrators only', function () {
    $directory = \App\Services\DemoVideoStore::directory();
    @mkdir($directory, 0775, true);
    $slug = \App\Services\DemoVideoStore::slug($this->client);
    file_put_contents($directory.'/'.$slug.'.webm', 'synthetic-video-bytes');

    try {
        $users = collect($this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk()->json('data.users'))->keyBy('id');
        expect($users[$this->client->id]['demo_video'])->toBeTrue()
            ->and($users[$this->admin->id]['demo_video'])->toBeFalse();

        $this->actingAs($this->admin)->get('/api/admin/users/'.$this->client->id.'/demo-video')
            ->assertOk()
            ->assertHeader('Content-Type', 'video/webm')
            ->assertDownload('recorrido-'.$slug.'.webm');
        $this->actingAs($this->admin)->get('/api/admin/users/'.$this->admin->id.'/demo-video')->assertNotFound();
        $this->actingAs($this->client)->get('/api/admin/users/'.$this->client->id.'/demo-video')->assertForbidden();
    } finally {
        @unlink($directory.'/'.$slug.'.webm');
    }
});
