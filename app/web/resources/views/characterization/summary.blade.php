<!DOCTYPE html>
@php
    use Illuminate\Support\Arr;
    use App\Support\CharacterizationOptions;
    use App\Support\DisplayLabels;

    $profile = Arr::get($formData, 'company_profile', []);
    $operations = Arr::get($formData, 'operations', []);
    $activity = Arr::get($formData, 'activity_questions', []);
    $companyName = $profile['company_name'] ?? '—';
    $status = $characterization->status;
    $statusTone = $status === 'completed' ? 'ok' : (in_array($status, ['failed', 'timed_out'], true) ? 'bad' : 'wait');
    $periodStart = $profile['reporting_period_start'] ?? null;
    $periodEnd = $profile['reporting_period_end'] ?? null;
    $employees = $employeeCountRangeOptions[$operations['employee_count_range'] ?? ''] ?? ($operations['employee_count'] ?? null);
    $revenue = $revenueRangeOptions[$operations['revenue_range'] ?? ''] ?? ($operations['revenue'] ?? null);
    $yesNo = CharacterizationOptions::yesNoUnknown();
    $questions = CharacterizationOptions::activityQuestions();
    $pillars = [
        'E' => ['label' => __('Environment'), 'color' => '#15803d', 'bg' => '#dcfce7'],
        'S' => ['label' => __('Social'), 'color' => '#1d4ed8', 'bg' => '#dbeafe'],
        'G' => ['label' => __('Governance'), 'color' => '#6d28d9', 'bg' => '#ede9fe'],
    ];
    $pillarOf = static fn (string $code): string => array_key_exists(strtoupper(substr($code, 0, 1)), $pillars) ? strtoupper(substr($code, 0, 1)) : 'G';
    $byStandard = [];
    $pillarCounts = ['E' => 0, 'S' => 0, 'G' => 0];
    foreach ($selectedTopics as $topic) {
        $code = (string) ($topic['esrs_code'] ?? '');
        $standard = strtoupper(preg_replace('/[^A-Za-z0-9].*$/', '', $code) ?: $code);
        $byStandard[$standard]['pillar'] = $pillarOf($code);
        $byStandard[$standard]['theme'] = $topic['theme'][$locale] ?? $topic['theme']['es'] ?? '';
        $byStandard[$standard]['topics'][] = $topic;
        $pillarCounts[$pillarOf($code)]++;
    }
    uksort($byStandard, static function (string $a, string $b) use ($byStandard): int {
        $order = ['E' => 0, 'S' => 1, 'G' => 2];
        return [$order[$byStandard[$a]['pillar']], $a] <=> [$order[$byStandard[$b]['pillar']], $b];
    });
    $schemeLabel = static function (?string $scheme): string {
        $scheme = strtolower((string) $scheme);
        return str_contains($scheme, '17442') ? 'LEI' : (str_contains($scheme, 'eu-id') || str_contains($scheme, 'euid') ? 'EUID' : strtoupper($scheme));
    };
    $totalTopics = count($selectedTopics);
    $regions = Arr::get($formData, 'operations.regions', []);
    $stages = Arr::get($formData, 'operations.value_chain', []);
    $stockListed = $profile['stock_listed'] ?? null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Characterization Summary') }} · {{ $companyName }}</title>
    <style>
        @page { margin: 16mm 14mm 20mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; color: #1f2937; font-size: 9.5pt; line-height: 1.4; margin: 0; }
        h1, h2, h3, p { margin: 0; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 4px; }
        .footer .page:after { content: counter(page); }
        .hero { width: 100%; background: #4f46e5; color: #ffffff; border-radius: 10px; border-collapse: separate; }
        .hero td { padding: 16px 18px; vertical-align: top; }
        .hero .eyebrow { font-size: 8pt; letter-spacing: 1.5px; text-transform: uppercase; color: #c7d2fe; }
        .hero h1 { font-size: 20pt; font-weight: bold; margin-top: 4px; }
        .hero .meta { margin-top: 8px; font-size: 9pt; color: #e0e7ff; }
        .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 8pt; font-weight: bold; }
        .pill.ok { background: #dcfce7; color: #166534; }
        .pill.wait { background: #fef3c7; color: #92400e; }
        .pill.bad { background: #fee2e2; color: #991b1b; }
        .tiles { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 12px -6px 0 -6px; }
        .tiles td { width: 25%; background: #f5f3ff; border: 1px solid #e0e7ff; border-radius: 8px; padding: 9px 11px; vertical-align: top; }
        .tiles .label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; }
        .tiles .value { font-size: 12.5pt; font-weight: bold; color: #312e81; margin-top: 2px; }
        .tiles .sub { font-size: 8pt; color: #6b7280; margin-top: 1px; }
        .section { margin-top: 16px; }
        .section h2 { font-size: 12pt; color: #312e81; padding-bottom: 4px; border-bottom: 2px solid #c7d2fe; margin-bottom: 8px; }
        .cols { width: 100%; border-collapse: collapse; }
        .cols td { width: 50%; vertical-align: top; padding: 0 8px 0 0; }
        .kv { width: 100%; border-collapse: collapse; }
        .kv th { text-align: left; font-weight: normal; color: #6b7280; width: 42%; padding: 4px 6px 4px 0; border-bottom: 1px solid #f3f4f6; font-size: 8.5pt; vertical-align: top; }
        .kv td { padding: 4px 0; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .chip { display: inline-block; background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; border-radius: 999px; padding: 1px 8px; font-size: 8pt; margin: 0 4px 4px 0; }
        .muted { color: #9ca3af; }
        .answers { width: 100%; border-collapse: collapse; }
        .answers td { padding: 5px 6px 5px 0; border-bottom: 1px solid #f3f4f6; vertical-align: top; font-size: 8.5pt; }
        .answers td.a { width: 92px; text-align: right; padding-right: 0; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 999px; margin-right: 5px; }
        .ans { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 7.5pt; font-weight: bold; }
        .ans.yes { background: #dcfce7; color: #166534; }
        .ans.no { background: #f3f4f6; color: #4b5563; }
        .ans.not_sure { background: #fef3c7; color: #92400e; }
        .ans.none { background: #f3f4f6; color: #9ca3af; }
        .bar { width: 100%; border-collapse: collapse; height: 14px; margin: 6px 0 4px 0; }
        .bar td { height: 14px; padding: 0; }
        .legend { font-size: 8pt; color: #4b5563; }
        .legend .sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin: 0 4px 0 10px; vertical-align: middle; }
        .standard { margin-top: 8px; page-break-inside: avoid; border: 1px solid #e5e7eb; border-radius: 8px; }
        .standard .head { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; }
        .standard .code { display: inline-block; font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; font-weight: bold; padding: 1px 7px; border-radius: 4px; margin-right: 6px; }
        .standard .theme { font-weight: bold; font-size: 9.5pt; }
        .standard .count { float: right; font-size: 8pt; color: #6b7280; padding-top: 2px; }
        .standard ul { margin: 0; padding: 6px 10px 6px 26px; }
        .standard li { padding: 2px 0; font-size: 8.5pt; }
        .standard li .sub { color: #6b7280; }
        .note { margin-top: 10px; font-size: 8pt; color: #6b7280; }
    </style>
</head>
<body>
    <div class="footer">
        <table style="width:100%; border-collapse:collapse;"><tr>
            <td>{{ __('Prepared with :app', ['app' => 'Airis']) }} · {{ __('Characterization Summary') }} · {{ $companyName }}</td>
            <td style="text-align:right;">{{ __('Generated at :date', ['date' => now()->format('Y-m-d H:i')]) }} · {{ __('Page') }} <span class="page"></span></td>
        </tr></table>
    </div>

    <table class="hero">
        <tr>
            <td>
                <div class="eyebrow">{{ __('Characterization Summary') }}</div>
                <h1>{{ $companyName }}</h1>
                <div class="meta">
                    @if ($selectedNace)
                        {{ __('NACE Code') }} {{ $selectedNace['code'] }} · {{ $selectedNace['title'][$locale] ?? '—' }}
                    @else
                        {{ __('NACE Code') }}: —
                    @endif
                </div>
                <div class="meta">
                    {{ __('Headquarters country') }}: {{ $headquartersCountryOptions[$profile['headquarters_country'] ?? ''] ?? '—' }}
                    · {{ __('Reporting scope') }}: {{ $reportingScopeOptions[$profile['reporting_scope'] ?? ''] ?? '—' }}
                </div>
            </td>
            <td style="width: 32%; text-align: right;">
                <span class="pill {{ $statusTone }}">{{ DisplayLabels::code($status) }}</span>
                <div class="meta" style="margin-top: 10px;">{{ __('Completed at') }}<br><strong style="color:#fff;">{{ optional($characterization->completed_at)->format('Y-m-d H:i') ?? '—' }}</strong></div>
            </td>
        </tr>
    </table>

    <table class="tiles">
        <tr>
            <td>
                <div class="label">{{ __('Reporting year') }}</div>
                <div class="value">{{ $profile['reporting_year'] ?? '—' }}</div>
                <div class="sub">{{ $periodStart && $periodEnd ? $periodStart.' – '.$periodEnd : __('Reporting period').': —' }}</div>
            </td>
            <td>
                <div class="label">{{ __('Employees') }}</div>
                <div class="value">{{ $employees ?? '—' }}</div>
                <div class="sub">{{ __('Listed company') }}: {{ $stockListed === null ? '—' : ($stockListed ? __('Yes') : __('No')) }}</div>
            </td>
            <td>
                <div class="label">{{ __('Annual revenue') }}</div>
                <div class="value">{{ $revenue ?? '—' }}</div>
                <div class="sub">{{ __('Reporting currency') }}: {{ $reportingCurrencyOptions[$profile['reporting_currency'] ?? ''] ?? '—' }}</div>
            </td>
            <td>
                <div class="label">{{ __('ESRS Topics') }}</div>
                <div class="value">{{ $totalTopics }}</div>
                <div class="sub">{{ __(':count standards', ['count' => count($byStandard)]) }}</div>
            </td>
        </tr>
    </table>

    <div class="section">
        <h2>{{ __('Company Profile') }}</h2>
        <table class="cols">
            <tr>
                <td>
                    <table class="kv">
                        <tr><th>{{ __('Product/service type') }}</th><td>{{ $productServiceTypeOptions[$profile['product_service_type'] ?? ''] ?? '—' }}</td></tr>
                        <tr><th>{{ __('Countries with subsidiaries') }}</th><td>{{ $profile['num_subsidiaries_countries'] ?? '—' }}</td></tr>
                        <tr><th>{{ __('Entity identifier') }}</th><td>{{ $profile['entity_identifier'] ?? '—' }} @if (!empty($profile['entity_identifier_scheme']))<span class="muted">({{ $schemeLabel($profile['entity_identifier_scheme']) }})</span>@endif</td></tr>
                        <tr><th>{{ __('Reporting period') }}</th><td>{{ $periodStart && $periodEnd ? $periodStart.' – '.$periodEnd : '—' }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="kv">
                        <tr><th>{{ __('Regions of operation') }}</th><td>
                            @forelse ($regions as $region)<span class="chip">{{ $regionOptions[$region] ?? $region }}</span>@empty<span class="muted">—</span>@endforelse
                        </td></tr>
                        <tr><th>{{ __('Value chain stages') }}</th><td>
                            @forelse ($stages as $stage)<span class="chip">{{ $valueChainOptions[$stage] ?? $stage }}</span>@empty<span class="muted">—</span>@endforelse
                        </td></tr>
                        @if (!empty($operations['notes']))
                            <tr><th>{{ __('Notes') }}</th><td>{{ $operations['notes'] }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>{{ __('Activity profile') }}</h2>
        <table class="answers">
            @foreach ($questions as $key => $question)
                @php $answer = $activity[$key] ?? null; $cls = in_array($answer, ['yes', 'no', 'not_sure'], true) ? $answer : 'none'; @endphp
                <tr>
                    <td>{{ $question }}</td>
                    <td class="a"><span class="ans {{ $cls }}">{{ $cls === 'none' ? __('Not answered') : $yesNo[$answer] }}</span></td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="section">
        <h2>{{ __('ESRS Topics') }}</h2>
        @if ($totalTopics > 0)
            <p class="legend">{{ __('Topics proposed for the materiality review, grouped by standard.') }}</p>
            <table class="bar">
                <tr>
                    @foreach ($pillars as $key => $pillar)
                        @if ($pillarCounts[$key] > 0)
                            <td style="width: {{ round($pillarCounts[$key] * 100 / $totalTopics, 1) }}%; background: {{ $pillar['color'] }}; {{ $loop->first ? 'border-radius: 6px 0 0 6px;' : '' }} {{ $loop->last ? 'border-radius: 0 6px 6px 0;' : '' }}"></td>
                        @endif
                    @endforeach
                </tr>
            </table>
            <p class="legend">
                @foreach ($pillars as $key => $pillar)
                    <span class="sw" style="background: {{ $pillar['color'] }};"></span>{{ $pillar['label'] }}: <strong>{{ $pillarCounts[$key] }}</strong>
                @endforeach
            </p>
            @foreach ($byStandard as $standard => $group)
                @php $pillar = $pillars[$group['pillar']]; @endphp
                <div class="standard">
                    <div class="head">
                        <span class="count">{{ __(':count topics', ['count' => count($group['topics'])]) }}</span>
                        <span class="code" style="background: {{ $pillar['bg'] }}; color: {{ $pillar['color'] }};">{{ $standard }}</span>
                        <span class="theme">{{ $group['theme'] ?: $pillar['label'] }}</span>
                    </div>
                    <ul>
                        @foreach ($group['topics'] as $topic)
                            @php
                                $subtheme = $topic['subtheme'][$locale] ?? $topic['subtheme']['es'] ?? '';
                                $subtopic = $topic['subtopic'][$locale] ?? $topic['subtopic']['es'] ?? '';
                            @endphp
                            <li>{{ $subtheme ?: '—' }}@if ($subtopic && $subtopic !== $subtheme) <span class="sub">· {{ $subtopic }}</span>@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        @else
            <p class="muted">{{ __('No topics selected.') }}</p>
        @endif
        <p class="note">{{ __('This summary reflects the information entered in step 1 of the assistant and the topics proposed for the materiality review.') }}</p>
    </div>
</body>
</html>
