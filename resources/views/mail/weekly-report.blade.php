<x-mail::message>
# Weekly Uptime Report

**Team:** {{ $teamName }}
**Period:** {{ $report['period_start']->format('M j, Y') }} — {{ $report['period_end']->format('M j, Y') }}

## Overview

| Monitors | Overall Uptime | Avg Response Time |
|----------|---------------|-------------------|
| {{ $report['total_monitors'] }} | {{ number_format($report['overall_uptime'], 1) }}% | {{ $report['avg_response_time'] }}ms |

## Monitors

<x-mail::table>
| Monitor | Uptime | Avg Response | Worst Response | Status |
|---------|--------|-------------|----------------|--------|
@foreach ($report['monitors'] as $monitor)
| {{ $monitor['name'] }} | {{ number_format($monitor['uptime_pct'], 1) }}% | {{ $monitor['avg_response_ms'] }}ms | {{ $monitor['worst_response_ms'] }}ms | {{ ucfirst($monitor['status']) }} |
@endforeach
</x-mail::table>

## Incidents ({{ $report['incident_count'] }})

@if (count($report['incidents']) > 0)
<x-mail::table>
| Monitor | Cause | Started | Duration | Resolved |
|---------|-------|---------|----------|----------|
@foreach ($report['incidents'] as $incident)
| {{ $incident['monitor_name'] }} | {{ ucfirst(str_replace('_', ' ', $incident['cause'])) }} | {{ \Carbon\Carbon::parse($incident['started_at'])->format('M j H:i') }} | {{ $incident['duration_minutes'] !== null ? $incident['duration_minutes'].' min' : 'Ongoing' }} | {{ $incident['resolved_at'] ? 'Yes' : 'No' }} |
@endforeach
</x-mail::table>
@else
No incidents this week.
@endif

## Highlights

@if ($report['best_monitor'])
- **Best performing:** {{ $report['best_monitor'] }}
@endif
@if ($report['worst_monitor'] && $report['worst_monitor'] !== $report['best_monitor'])
- **Needs attention:** {{ $report['worst_monitor'] }}
@endif
- **Total downtime:** {{ $report['total_downtime_minutes'] }} min
@if ($report['longest_incident'])
- **Longest incident:** {{ $report['longest_incident']['monitor_name'] }} — {{ $report['longest_incident']['duration_minutes'] }} min
@endif

<x-mail::button :url="config('app.url').'/dashboard'">
View Dashboard
</x-mail::button>

Thanks,
{{ config('app.name') }}
</x-mail::message>
