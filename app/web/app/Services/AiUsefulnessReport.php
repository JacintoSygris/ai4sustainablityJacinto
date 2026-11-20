<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Measures how useful the AI proposal is: how many of its recommended topics the
 * clients keep (step 2 review and final confirmation) and how many topics the
 * clients add by themselves. Pure read model over stored characterizations.
 */
final class AiUsefulnessReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $users = User::query()->orderBy('id')->get()->keyBy('id');
        $rows = Characterization::query()
            ->whereIn('user_id', $users->keys()->all())
            ->orderBy('id')
            ->get()
            ->unique('user_id')
            ->map(fn (Characterization $characterization) => $this->companyRow($characterization, $users->get($characterization->user_id)))
            ->filter()
            ->values();

        $withProposal = $rows->where('ai_recommended_count', '>', 0);
        $withReview = $withProposal->where('review.available', true);
        $withFinal = $withProposal->where('final.available', true);

        $aiTotal = (int) $withProposal->sum('ai_recommended_count');
        $acceptedTotal = (int) $withReview->sum('review.accepted');
        $unsureTotal = (int) $withReview->sum('review.unsure');
        $rejectedTotal = (int) $withReview->sum('review.rejected');
        $aiReviewedTotal = (int) $withReview->sum('ai_recommended_count');
        $addedStep2Total = (int) $withReview->sum('review.added_by_client');
        $finalTotal = (int) $withFinal->sum('final.confirmed_count');
        $aiInFinalTotal = (int) $withFinal->sum('final.ai_kept');
        $aiFinalTotal = (int) $withFinal->sum('ai_recommended_count');
        $addedFinalTotal = (int) $withFinal->sum('final.added_by_client');

        return [
            'generated_at' => now()->toJSON(),
            'summary' => [
                'users' => $users->count(),
                'companies_with_proposal' => $withProposal->count(),
                'companies_with_review' => $withReview->count(),
                'companies_with_confirmation' => $withFinal->count(),
                'ai_recommended_total' => $aiTotal,
                'review' => [
                    'ai_recommended_total' => $aiReviewedTotal,
                    'accepted' => $acceptedTotal,
                    'unsure' => $unsureTotal,
                    'rejected' => $rejectedTotal,
                    'pending' => max($aiReviewedTotal - $acceptedTotal - $unsureTotal - $rejectedTotal, 0),
                    'acceptance_rate' => self::rate($acceptedTotal, $aiReviewedTotal),
                    'average_acceptance_rate' => self::average($withReview->pluck('review.acceptance_rate')),
                    'added_by_client' => $addedStep2Total,
                ],
                'final' => [
                    'ai_recommended_total' => $aiFinalTotal,
                    'confirmed_total' => $finalTotal,
                    'ai_kept' => $aiInFinalTotal,
                    'ai_discarded' => max($aiFinalTotal - $aiInFinalTotal, 0),
                    'added_by_client' => $addedFinalTotal,
                    // Share of the AI's recommendations that survived the final selection.
                    'precision' => self::rate($aiInFinalTotal, $aiFinalTotal),
                    // Share of the final selection that the AI had recommended.
                    'coverage' => self::rate($aiInFinalTotal, $finalTotal),
                    'average_precision' => self::average($withFinal->pluck('final.precision')),
                ],
                'acceptance_distribution' => $this->distribution($withReview->pluck('review.acceptance_rate')),
            ],
            'top_accepted_topics' => $this->topTopics($withReview->flatMap(fn (array $row) => $row['review']['accepted_topic_ids'])),
            'top_rejected_topics' => $this->topTopics($withReview->flatMap(fn (array $row) => $row['review']['rejected_topic_ids'])),
            'top_added_topics' => $this->topTopics(
                $withReview->flatMap(fn (array $row) => $row['review']['added_topic_ids'])
                    ->merge($withFinal->flatMap(fn (array $row) => $row['final']['added_topic_ids']))
            ),
            'companies' => $rows->map(function (array $row) {
                unset($row['review']['accepted_topic_ids'], $row['review']['rejected_topic_ids'], $row['review']['added_topic_ids'], $row['final']['added_topic_ids']);

                return $row;
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function companyRow(Characterization $characterization, ?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $aiTopicIds = $this->candidateTopicIds($characterization->result_data ?? []);
        $proposalTopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);
        if ($proposalTopicIds === [] && $aiTopicIds !== []) {
            $proposalTopicIds = $aiTopicIds;
        }

        $review = $this->reviewMetrics($characterization, $aiTopicIds);
        $final = $this->finalMetrics($characterization, $aiTopicIds);

        return [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'company_name' => Arr::get($characterization->form_data ?? [], 'company_profile.company_name'),
            'nace_code' => $characterization->nace_code,
            'status' => $characterization->status,
            'completed_at' => $characterization->completed_at?->toJSON(),
            'ai_recommended_count' => count($aiTopicIds),
            'proposal_count' => count($proposalTopicIds),
            'review' => $review,
            'final' => $final,
        ];
    }

    /**
     * @param  array<int, int>  $aiTopicIds
     * @return array<string, mixed>
     */
    private function reviewMetrics(Characterization $characterization, array $aiTopicIds): array
    {
        $review = Arr::get($characterization->form_data ?? [], 'materiality_proposal_review', []);
        $empty = [
            'available' => false,
            'status' => 'not_started',
            'accepted' => 0,
            'unsure' => 0,
            'rejected' => 0,
            'pending' => count($aiTopicIds),
            'acceptance_rate' => null,
            'added_by_client' => 0,
            'accepted_topic_ids' => [],
            'rejected_topic_ids' => [],
            'added_topic_ids' => [],
        ];

        if (! is_array($review) || $aiTopicIds === [] || $this->reviewPredatesProposal($characterization, $review)) {
            return $empty;
        }

        $actions = Arr::get($review, 'topic_actions', []);
        $actions = is_array($actions) ? $actions : [];
        $counts = ['accepted' => [], 'unsure' => [], 'rejected' => []];

        foreach ($aiTopicIds as $topicId) {
            $action = $actions[(string) $topicId] ?? $actions[$topicId] ?? null;
            if (is_string($action) && array_key_exists($action, $counts)) {
                $counts[$action][] = $topicId;
            }
        }

        $decided = count($counts['accepted']) + count($counts['unsure']) + count($counts['rejected']);
        if ($decided === 0) {
            return $empty;
        }

        $added = array_values(array_diff($this->topicIds($this->arrayOrEmpty(Arr::get($review, 'added_topic_ids'))), $aiTopicIds));

        return [
            'available' => true,
            'status' => $decided >= count($aiTopicIds) ? 'reviewed' : 'in_progress',
            'accepted' => count($counts['accepted']),
            'unsure' => count($counts['unsure']),
            'rejected' => count($counts['rejected']),
            'pending' => count($aiTopicIds) - $decided,
            'acceptance_rate' => self::rate(count($counts['accepted']), count($aiTopicIds)),
            'added_by_client' => count($added),
            'accepted_topic_ids' => $counts['accepted'],
            'rejected_topic_ids' => $counts['rejected'],
            'added_topic_ids' => $added,
        ];
    }

    /**
     * @param  array<int, int>  $aiTopicIds
     * @return array<string, mixed>
     */
    private function finalMetrics(Characterization $characterization, array $aiTopicIds): array
    {
        $confirmation = Arr::get($characterization->form_data ?? [], 'materiality_confirmation', []);
        $confirmedTopicIds = is_array($confirmation) && array_key_exists('confirmed_topic_ids', $confirmation)
            ? $this->topicIds($this->arrayOrEmpty($confirmation['confirmed_topic_ids']))
            : null;

        if ($confirmedTopicIds === null || $aiTopicIds === []) {
            return [
                'available' => false,
                'confirmed_count' => 0,
                'ai_kept' => 0,
                'ai_discarded' => 0,
                'added_by_client' => 0,
                'precision' => null,
                'coverage' => null,
                'added_topic_ids' => [],
            ];
        }

        $kept = array_values(array_intersect($aiTopicIds, $confirmedTopicIds));
        $added = array_values(array_diff($confirmedTopicIds, $aiTopicIds));

        return [
            'available' => true,
            'confirmed_count' => count($confirmedTopicIds),
            'ai_kept' => count($kept),
            'ai_discarded' => count($aiTopicIds) - count($kept),
            'added_by_client' => count($added),
            'precision' => self::rate(count($kept), count($aiTopicIds)),
            'coverage' => self::rate(count($kept), count($confirmedTopicIds)),
            'added_topic_ids' => $added,
        ];
    }

    /**
     * @param  array<string, mixed>  $review
     */
    private function reviewPredatesProposal(Characterization $characterization, array $review): bool
    {
        if (! $characterization->completed_at) {
            return false;
        }

        $reviewedAt = Arr::get($review, 'reviewed_at');
        if (! is_string($reviewedAt) || trim($reviewedAt) === '') {
            return true;
        }

        try {
            return Carbon::parse($reviewedAt)->lt($characterization->completed_at);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $topicIds
     * @return array<int, array<string, mixed>>
     */
    private function topTopics($topicIds, int $limit = 8): array
    {
        $counts = $topicIds->countBy()->sortDesc()->take($limit);
        $topics = EsrsTopic::query()->whereIn('id', $counts->keys()->all())->get()->keyBy('id');

        return $counts
            ->map(function (int $count, int $topicId) use ($topics) {
                $topic = $topics->get($topicId);

                return [
                    'id' => $topicId,
                    'esrs_code' => $topic?->esrs_code,
                    'label' => [
                        'es' => $topic?->subtopic_es ?: ($topic?->subtheme_es ?: $topic?->theme_es),
                        'en' => $topic?->subtopic_en ?: ($topic?->subtheme_en ?: $topic?->theme_en),
                    ],
                    'count' => $count,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Buckets of per-company acceptance rates for a simple bar chart.
     *
     * @param  \Illuminate\Support\Collection<int, float|null>  $rates
     * @return array<int, array<string, mixed>>
     */
    private function distribution($rates): array
    {
        $buckets = [
            ['key' => '0-25', 'min' => 0.0, 'max' => 0.25, 'count' => 0],
            ['key' => '25-50', 'min' => 0.25, 'max' => 0.5, 'count' => 0],
            ['key' => '50-75', 'min' => 0.5, 'max' => 0.75, 'count' => 0],
            ['key' => '75-100', 'min' => 0.75, 'max' => 1.0001, 'count' => 0],
        ];

        foreach ($rates as $rate) {
            if (! is_numeric($rate)) {
                continue;
            }
            foreach ($buckets as $index => $bucket) {
                if ($rate >= $bucket['min'] && $rate < $bucket['max']) {
                    $buckets[$index]['count']++;
                    break;
                }
            }
        }

        return array_map(fn (array $bucket) => ['key' => $bucket['key'], 'count' => $bucket['count']], $buckets);
    }

    private static function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, float|null>  $values
     */
    private static function average($values): ?float
    {
        $numeric = $values->filter(fn ($value) => is_numeric($value))->values();

        return $numeric->isEmpty() ? null : round((float) $numeric->avg(), 4);
    }

    /**
     * @param  array<string, mixed>  $resultData
     * @return array<int, int>
     */
    private function candidateTopicIds(array $resultData): array
    {
        return collect(Arr::get($resultData, 'candidate_topics', []))
            ->filter(fn ($topic) => is_array($topic))
            ->map(fn (array $topic) => (int) Arr::get($topic, 'ar16_topic_id'))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function topicIds(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
