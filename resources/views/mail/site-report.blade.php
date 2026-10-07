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

    $fmtPct = fn (?float $v, int $d = 1) => \App\Support\ReportFormatter::percent($v, $d);
    $fmtMs = fn (?int $v) => \App\Support\ReportFormatter::milliseconds($v);
    $fmtNum = fn (?float $v, int $d = 0) => \App\Support\ReportFormatter::number($v, $d);
    $fmtDelta = fn (?array $delta, int $d = 0, string $unit = '') => $delta === null
        ? null
        : \App\Support\ReportFormatter::signed($delta['diff'], $d, $unit);
    $t = fn (string $key) => __('reports.sections.'.$key, [], 'fr');

    // Gauge segment-fill colors (the bar itself).
    $gaugeColor = fn (string $color) => match ($color) {
        'green' => '#15803D',
        'yellow' => '#CA8A04',
        'orange' => '#EA580C',
        default => '#B91C1C', // red
    };
    // Gauge TEXT colors (band label, Lighthouse scores) — darker variants of
    // the segment fill so yellow/orange stay readable as text, not just as a
    // background swatch.
    $gaugeTextColor = fn (string $color) => match ($color) {
        'green' => '#15803D',
        'yellow' => '#A16207',
        'orange' => '#C2410C',
        default => '#B91C1C', // red
    };
    $deltaToneColor = fn (string $tone) => match ($tone) {
        'good' => $positive,
        'bad' => $negative,
        default => $muted,
    };
    $fmtGaugeValue = fn (array $gauge) => \App\Support\ReportFormatter::number($gauge['value'], $gauge['decimals']).($gauge['unit'] !== '' ? ' '.$gauge['unit'] : '');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $frequencyLabel }}</title>
</head>
<body style="margin:0; padding:0; background-color:#EFEFEF; font-family:'Inter Tight', Inter, -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#EFEFEF; padding:32px 0;">
<tr>
<td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px; background-color:#FFFFFF;">

    {{-- Header --}}
    <tr>
        <td style="background-color:{{ $ink }}; padding:24px 32px;">
            <span style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:16px; letter-spacing:2px; color:#FFFFFF; text-transform:uppercase;">RADIANK</span>
            <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:11px; letter-spacing:1px; color:#9CA3AF; text-transform:uppercase; margin-top:6px;">
                {{ $frequencyLabel }}
            </div>
        </td>
    </tr>

    {{-- Title --}}
    <tr>
        <td style="padding:32px 32px 8px;">
            <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:11px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase;">
                {{ $report['site']['domain'] }}
            </div>
            <div style="font-size:24px; font-weight:500; color:{{ $ink }}; letter-spacing:-0.5px; margin-top:6px;">
                {{ $siteLabel }} — {{ $report['period']['label'] }}
            </div>
        </td>
    </tr>

    {{-- "En bref" summary --}}
    <tr>
        <td style="padding:0 32px 16px;">
            <p style="font-size:14px; line-height:1.5; color:{{ $ink }}; margin:0; padding:14px 16px; background-color:#F5F5F4; border-radius:6px;">
                {{ $report['summary'] }}
            </p>
        </td>
    </tr>

    @if (count($report['gauges']) > 0)
    {{-- "Où se situe votre site" gauges --}}
    <tr>
        <td style="padding:0 32px 4px;">
            <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:11px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase;">
                {{ __('reports.gauges.title', [], 'fr') }}
            </div>
            <p style="font-size:12px; color:{{ $secondary }}; margin:6px 0 0;">{{ $t('gauges') }}</p>
        </td>
    </tr>
    <tr>
        <td style="padding:8px 32px 16px;">
            @foreach ($report['gauges'] as $gauge)
            @continue(in_array($gauge['metric_key'], ['lighthouse_accessibility', 'lighthouse_best_practices'], true))
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px; padding-bottom:18px; border-bottom:1px solid #EDEDED;">
                <tr>
                    <td>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:6px;">
                            <tr>
                                <td style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase;">
                                    {{ $gauge['label'] }}
                                </td>
                                <td align="right" style="font-size:12px; color:{{ $ink }};">
                                    {{ $gauge['display_value'] ?? $fmtGaugeValue($gauge) }}
                                    <span style="color:{{ $gaugeTextColor($gauge['color']) }}; font-weight:500;">({{ $gauge['band_label'] }})</span>
                                </td>
                            </tr>
                        </table>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:2px; border-collapse:collapse;">
                            <tr>
                                @foreach ($gauge['segments'] as $segment)
                                <td width="{{ $segment['width_pct'] }}%" style="height:8px; line-height:8px; font-size:1px; background-color:{{ $gaugeColor($segment['color']) }};">&nbsp;</td>
                                @endforeach
                            </tr>
                        </table>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="{{ $gauge['marker_pct'] }}%" style="font-size:1px; line-height:1px;">&nbsp;</td>
                                <td style="font-size:11px; color:{{ $ink }}; white-space:nowrap;">▲</td>
                            </tr>
                        </table>
                        @if (($gauge['world']['type'] ?? null) === 'median')
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="{{ $gauge['world']['marker_pct'] }}%" style="font-size:1px; line-height:1px;">&nbsp;</td>
                                <td style="font-size:10px; color:{{ $muted }}; white-space:nowrap;">△ {{ __('reports.gauges.world.tick_label', [], 'fr') }}</td>
                            </tr>
                        </table>
                        @endif
                        @if (($gauge['delta_text'] ?? null) !== null)
                        <p style="font-size:11px; color:{{ $deltaToneColor($gauge['delta_tone']) }}; margin:4px 0 0;">{{ $gauge['delta_text'] }}</p>
                        @endif
                        @if (($gauge['world']['type'] ?? null) === 'good_share')
                        <p style="font-size:12px; color:{{ $secondary }}; margin:4px 0 0;">{{ $gauge['world']['sentence'] }}</p>
                        @endif
                        <p style="font-size:10px; color:{{ $muted }}; margin:4px 0 0;">
                            Échelle : {{ $gauge['source'] }}@if ($gauge['world'] !== null) · Moyenne mondiale : {{ __('reports.gauges.world.source', [], 'fr') }}@endif
                        </p>
                    </td>
                </tr>
            </table>
            @endforeach
        </td>
    </tr>
    @endif

    @if (count($report['monitors']) > 0)
    {{-- Monitors table --}}
    <tr>
        <td style="padding:24px 32px 4px;">
            <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:11px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase;">
                MONITEURS
            </div>
            <p style="font-size:12px; color:{{ $secondary }}; margin:6px 0 0;">{{ $t('monitors') }}</p>
        </td>
    </tr>
    <tr>
        <td style="padding:8px 32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                <tr style="border-bottom:1px solid {{ $hairline }};">
                    <td style="padding:8px 0; font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $muted }}; text-transform:uppercase;">Nom</td>
                    <td style="padding:8px 0; font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $muted }}; text-transform:uppercase;" align="right">Disponibilité</td>
                    <td style="padding:8px 0; font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $muted }}; text-transform:uppercase;" align="right">Réponse</td>
                    <td style="padding:8px 0; font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $muted }}; text-transform:uppercase;" align="right">État</td>
                </tr>
                @foreach ($report['monitors'] as $monitor)
                <tr style="border-bottom:1px solid {{ $hairline }};">
                    <td style="padding:10px 0; font-size:13px; color:{{ $ink }};">{{ $monitor['name'] }}</td>
                    <td style="padding:10px 0; font-size:13px; color:{{ $ink }};" align="right">{{ $fmtPct($monitor['uptime_pct']) ?? '—' }}</td>
                    <td style="padding:10px 0; font-size:13px; color:{{ $ink }};" align="right">{{ $fmtMs($monitor['avg_response_ms']) ?? '—' }}</td>
                    <td style="padding:10px 0; font-size:13px;" align="right">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background-color:{{ $monitor['latest_status'] === 'up' ? '#22C55E' : ($monitor['latest_status'] === 'down' ? $negative : $muted) }};"></span>
                    </td>
                </tr>
                @endforeach
            </table>
        </td>
    </tr>
    @endif

    @if (count($report['incidents']) > 0)
    {{-- Incidents --}}
    <tr>
        <td style="padding:24px 32px 4px;">
            <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:11px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase;">
                INCIDENTS DE LA PÉRIODE
            </div>
            <p style="font-size:12px; color:{{ $secondary }}; margin:6px 0 0;">{{ $t('incidents') }}</p>
        </td>
    </tr>
    <tr>
        <td style="padding:8px 32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                <tr style="border-bottom:1px solid {{ $hairline }};">
                    <td style="padding:8px 0; font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $muted }}; text-transform:uppercase;">Moniteur</td>
                    <td style="padding:8px 0; font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $muted }}; text-transform:uppercase;">Cause</td>
                    <td style="padding:8px 0; font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $muted }}; text-transform:uppercase;">Début</td>
                    <td style="padding:8px 0; font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $muted }}; text-transform:uppercase;" align="right">Durée</td>
                </tr>
                @foreach ($report['incidents'] as $incident)
                <tr style="border-bottom:1px solid {{ $hairline }};">
                    <td style="padding:10px 0; font-size:13px; color:{{ $ink }};">{{ $incident['monitor_name'] }}</td>
                    <td style="padding:10px 0; font-size:13px; color:{{ $ink }};">{{ str_replace('_', ' ', $incident['cause']) }}</td>
                    <td style="padding:10px 0; font-size:13px; color:{{ $ink }};">{{ $incident['started_at']->format('d/m H:i') }}</td>
                    <td style="padding:10px 0; font-size:13px; color:{{ $ink }};" align="right">{{ $incident['duration_minutes'] !== null ? $incident['duration_minutes'].' min' : 'en cours' }}</td>
                </tr>
                @endforeach
            </table>
        </td>
    </tr>
    @endif

    @if ($report['gsc'] !== null)
    {{-- GSC --}}
    <tr>
        <td style="padding:24px 32px 4px;">
            <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:11px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase;">
                RECHERCHE GOOGLE (GSC)
                <span style="color:{{ $muted }}; font-weight:400;">· 28 DERNIERS JOURS</span>
            </div>
            <p style="font-size:12px; color:{{ $secondary }}; margin:6px 0 0;">{{ $t('gsc') }}</p>
        </td>
    </tr>
    <tr>
        <td style="padding:8px 32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td width="25%">
                        <div style="font-size:20px; font-weight:400; color:{{ $ink }};">{{ $fmtNum($report['gsc']['clicks']) ?? '—' }}</div>
                        <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase; margin-top:2px;">Clics</div>
                        @if ($report['gsc']['clicks_delta'])
                        <div style="font-size:10px; color:{{ $toneColor($report['gsc']['clicks_delta']) }}; margin-top:2px;">{{ $fmtDelta($report['gsc']['clicks_delta']) }}</div>
                        @endif
                    </td>
                    <td width="25%">
                        <div style="font-size:20px; font-weight:400; color:{{ $ink }};">{{ $fmtNum($report['gsc']['impressions']) ?? '—' }}</div>
                        <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase; margin-top:2px;">Impressions</div>
                    </td>
                    <td width="25%">
                        <div style="font-size:20px; font-weight:400; color:{{ $ink }};">{{ $fmtPct($report['gsc']['ctr']) ?? '—' }}</div>
                        <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase; margin-top:2px;">CTR</div>
                    </td>
                    <td width="25%">
                        <div style="font-size:20px; font-weight:400; color:{{ $ink }};">{{ $fmtNum($report['gsc']['position'], 1) ?? '—' }}</div>
                        <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase; margin-top:2px;">Position moy.</div>
                        @if ($report['gsc']['position_delta'])
                        <div style="font-size:10px; color:{{ $toneColor($report['gsc']['position_delta']) }}; margin-top:2px;">{{ $fmtDelta($report['gsc']['position_delta'], 1) }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    @endif

    @if ($report['ga4'] !== null)
    {{-- GA4 --}}
    <tr>
        <td style="padding:24px 32px 4px;">
            <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:11px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase;">
                ANALYTICS (GA4)
                <span style="color:{{ $muted }}; font-weight:400;">· 28 DERNIERS JOURS</span>
            </div>
            <p style="font-size:12px; color:{{ $secondary }}; margin:6px 0 0;">{{ $t('ga4') }}</p>
        </td>
    </tr>
    <tr>
        <td style="padding:8px 32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td width="50%">
                        <div style="font-size:20px; font-weight:400; color:{{ $ink }};">{{ $fmtNum($report['ga4']['users']) ?? '—' }}</div>
                        <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase; margin-top:2px;">Utilisateurs</div>
                    </td>
                    <td width="50%">
                        <div style="font-size:20px; font-weight:400; color:{{ $ink }};">{{ $fmtNum($report['ga4']['sessions']) ?? '—' }}</div>
                        <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:10px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase; margin-top:2px;">Sessions</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    @endif

    @if (count($report['insights']) > 0)
    {{-- Insights --}}
    <tr>
        <td style="padding:24px 32px 4px;">
            <div style="font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:11px; letter-spacing:1px; color:{{ $secondary }}; text-transform:uppercase;">
                À SURVEILLER
            </div>
            <p style="font-size:12px; color:{{ $secondary }}; margin:6px 0 0;">{{ $t('insights') }}</p>
        </td>
    </tr>
    <tr>
        <td style="padding:8px 32px 16px;">
            @foreach ($report['insights'] as $insight)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
                <tr>
                    <td width="16" valign="top" style="padding-top:4px;">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background-color:{{ $insight['severity'] === 'critical' ? $negative : $warning }};"></span>
                    </td>
                    <td style="font-size:13px; color:{{ $ink }}; padding-left:8px;">
                        {{ $insight['title'] }}
                    </td>
                </tr>
            </table>
            @endforeach
        </td>
    </tr>
    @endif

    {{-- CTA --}}
    <tr>
        <td style="padding:24px 32px 8px;">
            <a href="{{ route('sites.show', $report['site']['id']) }}" style="display:inline-block; background-color:#000000; color:#FFFFFF; font-family:'Space Mono', ui-monospace, Menlo, Consolas, monospace; font-size:12px; letter-spacing:1px; text-transform:uppercase; text-decoration:none; padding:14px 24px; border-radius:6px;">
                VOIR LE TABLEAU DE BORD →
            </a>
        </td>
    </tr>
    <tr>
        <td style="padding:0 32px 24px;">
            <p style="font-size:12px; color:{{ $muted }}; margin:0;">Le rapport complet est joint en PDF.</p>
        </td>
    </tr>

    {{-- Footer --}}
    <tr>
        <td style="padding:20px 32px; border-top:1px solid {{ $hairline }};">
            <p style="font-size:11px; color:{{ $muted }}; margin:0;">
                Rapport {{ $report['frequency'] === 'monthly' ? 'mensuel' : 'hebdomadaire' }} généré par Radiank ·
                <a href="{{ route('sites.edit', $report['site']['id']) }}" style="color:{{ $muted }};">modifier dans les réglages du site</a>
            </p>
        </td>
    </tr>

</table>
</td>
</tr>
</table>
</body>
</html>
