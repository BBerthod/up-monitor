@php
    $ink = '#0A0A0A';
    $secondary = '#5E5E5E';
    $muted = '#888888';
    $hairline = '#DEDDDC';
    $positive = '#166534';
    $negative = '#B91C1C';
    $warning = '#B45309';

    $toneColor = fn (?array $delta) => match ($delta['tone'] ?? null) {
        'good' => $positive,
        'bad' => $negative,
        default => $muted,
    };

    $frequencyLabel = $report['frequency'] === 'monthly' ? 'RAPPORT MENSUEL' : 'RAPPORT HEBDOMADAIRE';
    $siteLabel = $report['site']['alias'] ?? $report['site']['domain'];

    $fmtPct = fn (?float $v, int $d = 1) => \App\Support\ReportFormatter::percent($v, $d) ?? '—';
    $fmtMs = fn (?int $v) => \App\Support\ReportFormatter::milliseconds($v) ?? '—';
    $fmtNum = fn (?float $v, int $d = 0) => \App\Support\ReportFormatter::number($v, $d) ?? '—';
    $fmtDelta = fn (?array $delta, int $d = 0, string $unit = '') => $delta === null
        ? null
        : \App\Support\ReportFormatter::signed($delta['diff'], $d, $unit);
    $t = fn (string $key) => __('reports.sections.'.$key, [], 'fr');
    $trend = fn (string $key) => __('reports.trend.'.$key, [], 'fr');
    $lexicon = __('reports.lexicon', [], 'fr');

    // Gauge segment-fill colors (the bar itself).
    $gaugeColor = fn (string $color) => match ($color) {
        'green' => '#15803D',
        'yellow' => '#CA8A04',
        'orange' => '#EA580C',
        default => '#B91C1C', // red
    };
    // Gauge TEXT colors (band label, Lighthouse scores) — darker variants of
    // the segment fill so yellow/orange stay readable as text. Also replaces
    // the old hard-coded "green >= 90" Lighthouse tone rule: Lighthouse
    // scores below now read the SAME band a gauge for that metric would
    // show (SiteReportService::lighthouseColor()), so the two can never drift.
    $gaugeTextColor = fn (string $color) => match ($color) {
        'green' => '#15803D',
        'yellow' => '#A16207',
        'orange' => '#C2410C',
        default => '#B91C1C', // red
    };
    $fmtGaugeValue = fn (array $gauge) => \App\Support\ReportFormatter::number($gauge['value'], $gauge['decimals']).($gauge['unit'] !== '' ? ' '.$gauge['unit'] : '');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 36px; }
    body {
        font-family: "DejaVu Sans", Helvetica, sans-serif;
        color: {{ $ink }};
        font-size: 12px;
    }
    .mono {
        font-family: "DejaVu Sans Mono", Courier, monospace;
        letter-spacing: 1px;
        text-transform: uppercase;
        font-size: 9px;
        color: {{ $secondary }};
    }
    .intro { font-size: 10px; color: {{ $secondary }}; margin: 3px 0 0; }
    table { width: 100%; border-collapse: collapse; }
    .header-bar { background-color: {{ $ink }}; padding: 14px 18px; }
    .header-bar .wordmark {
        font-family: "DejaVu Sans Mono", Courier, monospace;
        font-size: 15px;
        letter-spacing: 2px;
        color: #FFFFFF;
    }
    .header-bar .sub { font-family: "DejaVu Sans Mono", Courier, monospace; color: #9CA3AF; margin-top: 4px; }
    .summary { margin-top: 14px; padding: 10px 12px; background-color: #F5F5F4; font-size: 11px; line-height: 1.5; }
    .section-title { padding: 16px 0 6px; }
    .big { font-size: 26px; font-weight: normal; color: {{ $ink }}; }
    .delta { font-size: 9px; margin-top: 2px; }
    .stat-cell { padding: 10px 12px 10px 0; border-top: 1px solid {{ $hairline }}; }
    .hairline-row td { border-bottom: 1px solid {{ $hairline }}; padding: 6px 0; }
    .th { font-family: "DejaVu Sans Mono", Courier, monospace; font-size: 8px; letter-spacing: 1px; text-transform: uppercase; color: {{ $muted }}; padding: 6px 0; border-bottom: 1px solid {{ $hairline }}; }
    .dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
    .footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid {{ $hairline }}; font-size: 9px; color: {{ $muted }}; }
    .lexicon-title { margin-top: 28px; padding-top: 10px; border-top: 1px solid {{ $hairline }}; font-family: "DejaVu Sans Mono", Courier, monospace; font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: {{ $secondary }}; }
    .lexicon-item { font-size: 9px; color: {{ $secondary }}; margin-top: 6px; line-height: 1.4; }
    .gauge-block { margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid #EDEDED; }
    .gauge-bar td { height: 8px; line-height: 8px; font-size: 1px; }
    .gauge-marker td { font-size: 9px; line-height: 9px; }
    .gauge-world { font-size: 9px; color: {{ $secondary }}; margin-top: 3px; }
    .gauge-source { font-size: 8px; color: {{ $muted }}; margin-top: 3px; }
</style>
</head>
<body>

<table>
    <tr>
        <td class="header-bar">
            <span class="wordmark">RADIANK</span>
            <div class="mono sub">{{ $frequencyLabel }}</div>
        </td>
    </tr>
</table>

<div style="margin-top:18px;">
    <div class="mono">{{ $report['site']['domain'] }}</div>
    <div style="font-size:20px; font-weight:normal; margin-top:4px;">{{ $siteLabel }} — {{ $report['period']['label'] }}</div>
</div>

<div class="summary">{{ $report['summary'] }}</div>

@if (count($report['gauges']) > 0)
<div class="section-title mono">{{ __('reports.gauges.title', [], 'fr') }}</div>
<div class="intro">{{ $t('gauges') }}</div>
<div style="margin-top:10px;">
    @foreach ($report['gauges'] as $gauge)
    <div class="gauge-block">
        <table>
            <tr>
                <td class="mono">{{ $gauge['label'] }}</td>
                <td align="right" style="font-size:11px;">
                    {{ $gauge['display_value'] ?? $fmtGaugeValue($gauge) }}
                    <span style="color: {{ $gaugeTextColor($gauge['color']) }};">({{ $gauge['band_label'] }})</span>
                </td>
            </tr>
        </table>
        <table class="gauge-bar" style="margin-top:3px;">
            <tr>
                @foreach ($gauge['segments'] as $segment)
                <td width="{{ $segment['width_pct'] }}%" style="background-color: {{ $gaugeColor($segment['color']) }};">&nbsp;</td>
                @endforeach
            </tr>
        </table>
        <table class="gauge-marker">
            <tr>
                <td width="{{ $gauge['marker_pct'] }}%">&nbsp;</td>
                <td>▲</td>
            </tr>
        </table>
        @if (($gauge['world']['type'] ?? null) === 'median')
        <table class="gauge-marker">
            <tr>
                <td width="{{ $gauge['world']['marker_pct'] }}%">&nbsp;</td>
                <td style="color: {{ $muted }};">△ {{ __('reports.gauges.world.tick_label', [], 'fr') }}</td>
            </tr>
        </table>
        @endif
        @if (($gauge['world']['type'] ?? null) === 'good_share')
        <div class="gauge-world">{{ $gauge['world']['sentence'] }}</div>
        @endif
        <div class="gauge-source">
            Échelle : {{ $gauge['source'] }}@if ($gauge['world'] !== null) · Moyenne mondiale : {{ __('reports.gauges.world.source', [], 'fr') }}@endif
        </div>
    </div>
    @endforeach
</div>
@endif

@if ($report['health'] !== null)
<div class="section-title mono">SCORE DE SANTÉ</div>
<div class="intro">{{ $t('health') }}</div>
<div class="big">
    {{ $report['health']['score'] }} / 100
    <span style="font-size:12px; color: {{ $report['health']['trend'] === 'up' ? $positive : ($report['health']['trend'] === 'down' ? $negative : $muted) }};">
        ({{ $trend($report['health']['trend']) }})
    </span>
</div>
@endif

<div class="section-title mono">DISPONIBILITÉ</div>
<div class="intro">{{ $t('uptime') }}</div>
<table style="margin-top:12px;">
    <tr>
        <td class="stat-cell" width="33%">
            <div class="big">{{ $fmtPct($report['uptime']['uptime_pct'], 2) }}</div>
            <div class="mono">DISPONIBILITÉ</div>
            @if ($fmtDelta($report['uptime']['uptime_pct_delta'], 1, ' pt'))
            <div class="delta" style="color: {{ $toneColor($report['uptime']['uptime_pct_delta']) }};">{{ $fmtDelta($report['uptime']['uptime_pct_delta'], 1, ' pt') }} {{ $report['comparison_label'] }}</div>
            @endif
        </td>
        <td class="stat-cell" width="33%">
            <div class="big">{{ $report['uptime']['incident_count'] }}</div>
            <div class="mono">INCIDENTS ({{ $report['uptime']['total_downtime_minutes'] }} min)</div>
        </td>
        <td class="stat-cell" width="34%">
            <div class="big">{{ $fmtMs($report['uptime']['avg_response_ms']) }}</div>
            <div class="mono">TEMPS DE RÉPONSE</div>
            <div class="intro">{{ $t('response_time') }}</div>
            @if ($fmtDelta($report['uptime']['avg_response_ms_delta'], 0, ' ms'))
            <div class="delta" style="color: {{ $toneColor($report['uptime']['avg_response_ms_delta']) }};">{{ $fmtDelta($report['uptime']['avg_response_ms_delta'], 0, ' ms') }} {{ $report['comparison_label'] }}</div>
            @endif
        </td>
    </tr>
</table>

@if (count($report['monitors']) > 0)
<div class="section-title mono">MONITEURS</div>
<div class="intro">{{ $t('monitors') }}</div>
<table style="margin-top:8px;">
    <tr class="hairline-row">
        <td class="th">Nom</td>
        <td class="th" align="right">Disponibilité</td>
        <td class="th" align="right">Réponse</td>
        <td class="th" align="right">État</td>
    </tr>
    @foreach ($report['monitors'] as $monitor)
    <tr class="hairline-row">
        <td>{{ $monitor['name'] }}</td>
        <td align="right">{{ $fmtPct($monitor['uptime_pct']) }}</td>
        <td align="right">{{ $fmtMs($monitor['avg_response_ms']) }}</td>
        <td align="right">
            <span class="dot" style="background-color: {{ $monitor['latest_status'] === 'up' ? '#22C55E' : ($monitor['latest_status'] === 'down' ? $negative : $muted) }};"></span>
        </td>
    </tr>
    @endforeach
</table>
@endif

@if (count($report['incidents']) > 0)
<div class="section-title mono">INCIDENTS DE LA PÉRIODE</div>
<div class="intro">{{ $t('incidents') }}</div>
<table style="margin-top:8px;">
    <tr class="hairline-row">
        <td class="th">Moniteur</td>
        <td class="th">Cause</td>
        <td class="th">Début</td>
        <td class="th" align="right">Durée</td>
    </tr>
    @foreach ($report['incidents'] as $incident)
    <tr class="hairline-row">
        <td>{{ $incident['monitor_name'] }}</td>
        <td>{{ str_replace('_', ' ', $incident['cause']) }}</td>
        <td>{{ $incident['started_at']->format('d/m H:i') }}</td>
        <td align="right">{{ $incident['duration_minutes'] !== null ? $incident['duration_minutes'].' min' : 'en cours' }}</td>
    </tr>
    @endforeach
</table>
@endif

@if ($report['gsc'] !== null)
<div class="section-title mono">RECHERCHE GOOGLE (GSC) <span style="color: {{ $muted }};">· 28 DERNIERS JOURS</span></div>
<div class="intro">{{ $t('gsc') }}</div>
<table style="margin-top:8px;">
    <tr>
        <td width="25%">
            <div style="font-size:16px;">{{ $fmtNum($report['gsc']['clicks']) }}</div>
            <div class="mono">Clics</div>
            @if ($fmtDelta($report['gsc']['clicks_delta']))
            <div class="delta" style="color: {{ $toneColor($report['gsc']['clicks_delta']) }};">{{ $fmtDelta($report['gsc']['clicks_delta']) }}</div>
            @endif
        </td>
        <td width="25%">
            <div style="font-size:16px;">{{ $fmtNum($report['gsc']['impressions']) }}</div>
            <div class="mono">Impressions</div>
        </td>
        <td width="25%">
            <div style="font-size:16px;">{{ $fmtPct($report['gsc']['ctr']) }}</div>
            <div class="mono">CTR</div>
        </td>
        <td width="25%">
            <div style="font-size:16px;">{{ $fmtNum($report['gsc']['position'], 1) }}</div>
            <div class="mono">Position moy.</div>
            @if ($fmtDelta($report['gsc']['position_delta'], 1))
            <div class="delta" style="color: {{ $toneColor($report['gsc']['position_delta']) }};">{{ $fmtDelta($report['gsc']['position_delta'], 1) }}</div>
            @endif
        </td>
    </tr>
</table>
@endif

@if ($report['ga4'] !== null)
<div class="section-title mono">ANALYTICS (GA4) <span style="color: {{ $muted }};">· 28 DERNIERS JOURS</span></div>
<div class="intro">{{ $t('ga4') }}</div>
<table style="margin-top:8px;">
    <tr>
        <td width="50%">
            <div style="font-size:16px;">{{ $fmtNum($report['ga4']['users']) }}</div>
            <div class="mono">Utilisateurs</div>
        </td>
        <td width="50%">
            <div style="font-size:16px;">{{ $fmtNum($report['ga4']['sessions']) }}</div>
            <div class="mono">Sessions</div>
        </td>
    </tr>
</table>
@endif

@if ($report['lighthouse'] !== null)
<div class="section-title mono">LIGHTHOUSE</div>
<div class="intro">{{ $t('lighthouse') }}</div>
<table style="margin-top:8px;">
    <tr>
        @foreach ([
            ['performance', 'Performance'],
            ['accessibility', 'Accessibilité'],
            ['best_practices', 'Bonnes pratiques'],
            ['seo', 'SEO'],
        ] as [$key, $label])
        <td width="25%">
            <div style="font-size:16px; color: {{ $gaugeTextColor($report['lighthouse'][$key.'_color']) }};">{{ $report['lighthouse'][$key] }}</div>
            <div class="mono">{{ $label }}</div>
        </td>
        @endforeach
    </tr>
</table>
@endif

@if (count($report['insights']) > 0)
<div class="section-title mono">À SURVEILLER</div>
<div class="intro">{{ $t('insights') }}</div>
<table style="margin-top:8px;">
    @foreach ($report['insights'] as $insight)
    <tr class="hairline-row">
        <td width="16">
            <span class="dot" style="background-color: {{ $insight['severity'] === 'critical' ? $negative : $warning }};"></span>
        </td>
        <td>{{ $insight['title'] }}</td>
    </tr>
    @endforeach
</table>
@endif

<div class="footer">
    Rapport {{ $report['frequency'] === 'monthly' ? 'mensuel' : 'hebdomadaire' }} généré par Radiank — {{ $report['site']['domain'] }}
</div>

<div class="lexicon-title">LEXIQUE</div>
@foreach ($lexicon as $term => $definition)
<div class="lexicon-item">{{ $definition }}</div>
@endforeach

</body>
</html>
