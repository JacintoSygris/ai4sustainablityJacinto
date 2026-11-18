<!DOCTYPE html>
@php
    $summary = $report['summary'];
    $pct = fn ($value) => $value === null ? '—' : number_format($value * 100, 1, ',', '.').' %';
    $label = fn (array $topic) => trim(($topic['esrs_code'] ?? '').' '.(($topic['label'][$locale] ?? null) ?: ($topic['label']['es'] ?? '')));
    $maxBucket = max(1, max(array_column($summary['acceptance_distribution'], 'count')));
    $barWidth = fn ($value) => $value === null ? 0 : max(2, (int) round($value * 100));
@endphp
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('AI usefulness report') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; margin: 0; }
        h1 { font-size: 20px; margin: 0 0 4px 0; color: #4838bf; }
        h2 { font-size: 14px; margin: 22px 0 8px 0; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
        p { margin: 0 0 6px 0; }
        .muted { color: #6b7280; }
        .tiles { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin-left: -8px; }
        .tile { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; vertical-align: top; width: 25%; }
        .tile .value { font-size: 22px; font-weight: bold; color: #111827; }
        .tile .caption { font-size: 10px; color: #6b7280; margin-top: 2px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.data th, table.data td { border: 1px solid #e5e7eb; padding: 5px 6px; font-size: 10.5px; text-align: left; vertical-align: top; }
        table.data th { background: #f3f4f6; }
        .num { text-align: right; }
        .bar { background: #ebe8fb; height: 10px; width: 100%; }
        .bar span { display: block; height: 10px; background: #4838bf; }
        .bar-cell { width: 42%; }
        .note { font-size: 10px; color: #6b7280; margin-top: 10px; }
    </style>
</head>
<body>
    <h1>{{ __('AI usefulness report') }}</h1>
    <p class="muted">{{ __('Generated at :date', ['date' => now()->format('Y-m-d H:i')]) }} · {{ __(':count companies with an AI proposal', ['count' => $summary['companies_with_proposal']]) }}</p>
    <p>{{ __('How many of the topics the AI recommends do clients keep, and how many topics do they add on their own. "Review" refers to the decisions taken in step 2; "final selection" to the confirmed material topics of step 4.') }}</p>

    <h2>{{ __('Headline figures') }}</h2>
    <table class="tiles">
        <tr>
            <td class="tile">
                <div class="value">{{ $pct($summary['review']['acceptance_rate']) }}</div>
                <div class="caption">{{ __('AI topics accepted in the review (:accepted of :total)', ['accepted' => $summary['review']['accepted'], 'total' => $summary['review']['ai_recommended_total']]) }}</div>
            </td>
            <td class="tile">
                <div class="value">{{ $pct($summary['final']['precision']) }}</div>
                <div class="caption">{{ __('AI topics kept in the final selection (:kept of :total)', ['kept' => $summary['final']['ai_kept'], 'total' => $summary['final']['ai_recommended_total']]) }}</div>
            </td>
            <td class="tile">
                <div class="value">{{ $pct($summary['final']['coverage']) }}</div>
                <div class="caption">{{ __('Final material topics that the AI had recommended (:kept of :total)', ['kept' => $summary['final']['ai_kept'], 'total' => $summary['final']['confirmed_total']]) }}</div>
            </td>
            <td class="tile">
                <div class="value">{{ $summary['review']['added_by_client'] + $summary['final']['added_by_client'] }}</div>
                <div class="caption">{{ __('Topics added by clients (:review in the review, :final in the final selection)', ['review' => $summary['review']['added_by_client'], 'final' => $summary['final']['added_by_client']]) }}</div>
            </td>
        </tr>
    </table>

    <h2>{{ __('Review decisions on AI topics (step 2)') }}</h2>
    <table class="data">
        <tr>
            <th>{{ __('Decision') }}</th><th class="num">{{ __('Topics') }}</th><th class="bar-cell">{{ __('Share') }}</th>
        </tr>
        @foreach ([['label' => __('Accepted'), 'value' => $summary['review']['accepted']], ['label' => __('Unsure'), 'value' => $summary['review']['unsure']], ['label' => __('Rejected'), 'value' => $summary['review']['rejected']], ['label' => __('Pending'), 'value' => $summary['review']['pending']]] as $row)
            @php $share = $summary['review']['ai_recommended_total'] > 0 ? $row['value'] / $summary['review']['ai_recommended_total'] : null; @endphp
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ $row['value'] }}</td>
                <td><div class="bar"><span style="width: {{ $barWidth($share) }}%"></span></div> <span class="muted">{{ $pct($share) }}</span></td>
            </tr>
        @endforeach
    </table>
    <p class="note">{{ __('Average acceptance per company: :rate. Companies with a review: :count.', ['rate' => $pct($summary['review']['average_acceptance_rate']), 'count' => $summary['companies_with_review']]) }}</p>

    <h2>{{ __('Companies by acceptance rate') }}</h2>
    <table class="data">
        <tr><th>{{ __('Acceptance rate') }}</th><th class="num">{{ __('Companies') }}</th><th class="bar-cell"></th></tr>
        @foreach ($summary['acceptance_distribution'] as $bucket)
            <tr>
                <td>{{ $bucket['key'] }} %</td>
                <td class="num">{{ $bucket['count'] }}</td>
                <td><div class="bar"><span style="width: {{ (int) round($bucket['count'] / $maxBucket * 100) }}%"></span></div></td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('Final selection (step 4)') }}</h2>
    <table class="data">
        <tr><th>{{ __('Measure') }}</th><th class="num">{{ __('Value') }}</th></tr>
        <tr><td>{{ __('Companies with a confirmed selection') }}</td><td class="num">{{ $summary['companies_with_confirmation'] }}</td></tr>
        <tr><td>{{ __('AI topics kept') }}</td><td class="num">{{ $summary['final']['ai_kept'] }}</td></tr>
        <tr><td>{{ __('AI topics discarded') }}</td><td class="num">{{ $summary['final']['ai_discarded'] }}</td></tr>
        <tr><td>{{ __('Topics added by clients') }}</td><td class="num">{{ $summary['final']['added_by_client'] }}</td></tr>
        <tr><td>{{ __('Average precision per company') }}</td><td class="num">{{ $pct($summary['final']['average_precision']) }}</td></tr>
    </table>

    @if ($report['top_accepted_topics'] !== [] || $report['top_added_topics'] !== [])
        <h2>{{ __('Most frequent topics') }}</h2>
        <table class="data">
            <tr><th>{{ __('Accepted AI topics') }}</th><th class="num">{{ __('Companies') }}</th><th>{{ __('Topics added by clients') }}</th><th class="num">{{ __('Companies') }}</th></tr>
            @for ($i = 0; $i < max(count($report['top_accepted_topics']), count($report['top_added_topics'])); $i++)
                <tr>
                    <td>{{ isset($report['top_accepted_topics'][$i]) ? $label($report['top_accepted_topics'][$i]) : '' }}</td>
                    <td class="num">{{ $report['top_accepted_topics'][$i]['count'] ?? '' }}</td>
                    <td>{{ isset($report['top_added_topics'][$i]) ? $label($report['top_added_topics'][$i]) : '' }}</td>
                    <td class="num">{{ $report['top_added_topics'][$i]['count'] ?? '' }}</td>
                </tr>
            @endfor
        </table>
    @endif

    <h2>{{ __('Per company') }}</h2>
    <table class="data">
        <tr>
            <th>{{ __('Company') }}</th><th>{{ __('User') }}</th><th class="num">{{ __('AI topics') }}</th><th class="num">{{ __('Accepted') }}</th><th class="num">{{ __('Unsure') }}</th><th class="num">{{ __('Rejected') }}</th><th class="num">{{ __('Added') }}</th><th class="num">{{ __('Acceptance') }}</th><th class="num">{{ __('Final: AI kept') }}</th><th class="num">{{ __('Final: added') }}</th>
        </tr>
        @forelse ($report['companies'] as $company)
            <tr>
                <td>{{ $company['company_name'] ?: '—' }}</td>
                <td>{{ $company['user_email'] }}</td>
                <td class="num">{{ $company['ai_recommended_count'] }}</td>
                <td class="num">{{ $company['review']['accepted'] }}</td>
                <td class="num">{{ $company['review']['unsure'] }}</td>
                <td class="num">{{ $company['review']['rejected'] }}</td>
                <td class="num">{{ $company['review']['added_by_client'] }}</td>
                <td class="num">{{ $pct($company['review']['acceptance_rate']) }}</td>
                <td class="num">{{ $company['final']['available'] ? $company['final']['ai_kept'].' / '.$company['ai_recommended_count'] : '—' }}</td>
                <td class="num">{{ $company['final']['available'] ? $company['final']['added_by_client'] : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="muted">{{ __('No company has an AI proposal yet.') }}</td></tr>
        @endforelse
    </table>
</body>
</html>
