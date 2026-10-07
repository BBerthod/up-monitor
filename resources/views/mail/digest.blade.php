<x-mail::message>
# Weekly SEO Digest

**Team:** {{ $teamName }}
**Period:** {{ $digest['period_start']->format('M j, Y') }} — {{ $digest['period_end']->format('M j, Y') }}

---

## This Week in Brief

{{-- AI narrative: escaped then nl2br so bullet lines render correctly in HTML mail. --}}
{{-- e() escapes XSS; nl2br preserves newline-separated bullets without Markdown. --}}
{!! nl2br(e($digest['narrative'])) !!}

---

## Portfolio Health

<x-mail::table>
| Site | Score | Grade | Trend |
|------|-------|-------|-------|
@foreach ($digest['health'] as $site)
| {{ $site['name'] }} | {{ $site['score'] }} | {{ $site['grade'] }} | {{ match($site['trend']) { 'up' => '↑', 'down' => '↓', default => '→' } }} |
@endforeach
</x-mail::table>

---

## What Changed This Week

@if (count($digest['what_changed']) > 0)
<x-mail::table>
| Site | Metric | Change |
|------|--------|--------|
@foreach ($digest['what_changed'] as $change)
| {{ $change['site'] }} | {{ $change['label'] }} | {{ $change['delta_pct'] > 0 ? '+' : '' }}{{ round($change['delta_pct'], 1) }}% — {{ $change['direction'] === 'improvement' ? 'improved' : 'declined' }} |
@endforeach
</x-mail::table>
@else
No significant changes detected this week.
@endif

---

@if (count($digest['action_plan']['actions']) > 0)
## Priority Actions

{{-- Prioritised SEO to-do list: impact / effort ROI, highest first. --}}
{{-- Impact and Effort are both on 1-100 / 1-5 scales respectively.   --}}
@foreach ($digest['action_plan']['actions'] as $action)
- {{ $action['action'] }} — **Impact {{ $action['impact'] }}/100** · Effort {{ $action['effort'] }}/5
@endforeach

---

@endif

## SEO Quick Wins

@if (count($digest['top_opportunities']) > 0)
@foreach ($digest['top_opportunities'] as $opp)
- **{{ $opp['query'] ?? $opp['title'] }}** — ~{{ number_format($opp['estimated_gain'], 0) }} clicks/mo — {{ $opp['site'] }}
@endforeach
@else
No quick wins detected this week.
@endif

---

@if ($digest['alerts']['core_update_suspected'] || count($digest['alerts']['ga4_broken_sites']) > 0)
## Alerts

@if ($digest['alerts']['core_update_suspected'])
⚠️ Portfolio-wide ranking movement detected (possible Google core update).
@endif
@if (count($digest['alerts']['ga4_broken_sites']) > 0)
⚠️ GA4 tracking may be broken on: {{ implode(', ', $digest['alerts']['ga4_broken_sites']) }}
@endif

---
@endif

@if (count($digest['server_health'] ?? []) > 0)
## Server Health

@foreach ($digest['server_health'] as $sh)
@php
    $icon = strtoupper($sh['severity']) === 'CRITICAL' ? '🔴' : '🟠';
    $sites = $sh['sites'] ?? [];
    $affects = '';
    if (count($sites) > 0) {
        $affects = ' — affects '.implode(', ', array_slice($sites, 0, 5));
        if (count($sites) > 5) {
            $affects .= ' +'.(count($sites) - 5).' more';
        }
    }
@endphp
{{ $icon }} **{{ $sh['server'] }}** — {{ strtoupper($sh['metric']) }} at {{ number_format((float) ($sh['value'] ?? 0), 0) }}%{{ $affects }}

@endforeach

---
@endif

## Uptime Summary

<x-mail::table>
| Metric | Value |
|--------|-------|
| Overall uptime | {{ number_format($digest['report']['overall_uptime'], 1) }}% |
| Incidents | {{ $digest['report']['incident_count'] }} |
| Total downtime | {{ $digest['report']['total_downtime_minutes'] }} min |
@if ($digest['report']['best_monitor'])
| Best performer | {{ $digest['report']['best_monitor'] }} |
@endif
@if ($digest['report']['worst_monitor'] && $digest['report']['worst_monitor'] !== $digest['report']['best_monitor'])
| Needs attention | {{ $digest['report']['worst_monitor'] }} |
@endif
</x-mail::table>

<x-mail::button :url="config('app.url').'/dashboard'">
View Dashboard
</x-mail::button>

Thanks,
{{ config('app.name') }}
</x-mail::message>
