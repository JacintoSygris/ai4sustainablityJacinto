<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\AiUsefulnessReport;
use App\Services\DemoVideoStore;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\PlatformAdmin;
use App\Support\DoubleMaterialityProcessState;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Read-only overview for platform administrators (see PlatformAdmin): every
 * account's progress and report links, plus the AI usefulness report.
 */
class AdminOverviewController extends Controller
{
    public function __construct(
        private readonly AiUsefulnessReport $aiUsefulness,
        private readonly ReportController $reports,
    ) {}

    public function users(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $this->authorizeAdmin($request);

        $users = User::query()->orderBy('id')->get();
        $characterizations = Characterization::query()
            ->whereIn('user_id', $users->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');
        $snapshots = ReportSnapshot::query()
            ->with('approval')
            ->whereIn('user_id', $users->pluck('id')->all())
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('user_id');

        $rows = $users->map(function (User $user) use ($characterizations, $snapshots, $request, $datapoints): array {
            /** @var Characterization|null $characterization */
            $characterization = $characterizations->get($user->id);
            $formData = $characterization?->form_data ?? [];
            $userSnapshots = $snapshots->get($user->id, collect());
            $approved = $userSnapshots->filter(fn (ReportSnapshot $snapshot) => $snapshot->approval !== null);
            $review = Arr::get($formData, 'materiality_proposal_review', []);
            $confirmation = Arr::get($formData, 'materiality_confirmation', []);
            $responses = Arr::get($formData, 'esrs_datapoint_responses.responses', []);
            $responses = is_array($responses) ? array_filter($responses, fn ($response) => is_array($response) && in_array($response['status'] ?? null, ['completed', 'not_applicable'], true)) : [];

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'is_admin' => PlatformAdmin::isAdmin($user),
                'created_at' => $user->created_at?->toJSON(),
                // A recorded walkthrough of the account's data, when the operator stored one.
                'demo_video' => DemoVideoStore::exists($user),
                'characterization' => $characterization ? [
                    'id' => $characterization->id,
                    'status' => $characterization->status,
                    'company_name' => Arr::get($formData, 'company_profile.company_name'),
                    'nace_code' => $characterization->nace_code,
                    'submitted_at' => $characterization->submitted_at?->toJSON(),
                    'completed_at' => $characterization->completed_at?->toJSON(),
                    'updated_at' => $characterization->updated_at?->toJSON(),
                ] : null,
                'progress' => [
                    'proposal_completed' => $characterization?->status === Characterization::STATUS_COMPLETED,
                    'review_started' => is_array($review) && Arr::get($review, 'topic_actions', []) !== [],
                    'acta_registered' => (bool) (DoubleMaterialityProcessState::fromFormData(is_array($formData) ? $formData : [])['acta_registered'] ?? false),
                    'confirmed' => is_array($confirmation) && array_key_exists('confirmed_topic_ids', $confirmation),
                    'datapoint_responses' => count($responses),
                    'snapshots' => $userSnapshots->count(),
                    'approved_snapshots' => $approved->count(),
                    'latest_snapshot_at' => $userSnapshots->first()?->created_at?->toJSON(),
                    'report' => $characterization ? $this->reportReadiness($request, $user->id, $datapoints) : null,
                ],
            ];
        })->values()->all();

        return response()->json(['data' => ['users' => $rows, 'admin_domains' => PlatformAdmin::domains()]]);
    }

    public function demoVideo(Request $request, int $user)
    {
        $this->authorizeAdmin($request);

        $account = User::query()->findOrFail($user);
        $path = DemoVideoStore::path($account);
        abort_unless($path !== null, 404, __('No walkthrough video is stored for this account.'));

        return response()->download($path, DemoVideoStore::downloadName($account), ['Content-Type' => 'video/webm']);
    }

    public function aiUsefulness(Request $request)
    {
        $this->authorizeAdmin($request);

        $report = $this->aiUsefulness->build();
        $format = (string) $request->query('format', 'json');

        if ($format === 'pdf' || $format === 'html') {
            $view = view('admin.ai-usefulness', ['report' => $report, 'locale' => app()->getLocale()]);

            if ($format === 'html') {
                return $view;
            }

            $filename = app()->getLocale() === 'en' ? 'ai-usefulness-report.pdf' : 'informe-utilidad-ia.pdf';

            return Pdf::loadHTML($view->render())->setPaper('a4')->download($filename);
        }

        return response()->json(['data' => $report]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(PlatformAdmin::isAdmin($request->user()), 403, __('Only platform administrators can open this view.'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reportReadiness(Request $request, int $userId, EsrsDatapointCorpusBuilder $datapoints): ?array
    {
        try {
            $viewed = $request->duplicate(array_merge($request->query->all(), ['as_user' => (string) $userId]));
            $viewed->setUserResolver($request->getUserResolver());

            return $this->reports->exportReadiness($viewed, $datapoints);
        } catch (\Throwable) {
            return null;
        }
    }
}
