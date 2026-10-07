@use('App\Jobs\Notifications\SendInsightAlert')
<x-mail::message>
# {{ $title }}

<x-mail::table>
| Field    | Value                          |
|:---------|:-------------------------------|
| Site     | {{ $insight->site }}           |
| Type     | {{ $insight->type->label() }}  |
| Severity | {{ $insight->severity->label() }} |
</x-mail::table>

@php
    $payload = $insight->payload ?? [];
    $sites = !empty($payload['sites']) && is_array($payload['sites']) ? $payload['sites'] : [];
@endphp

@if (!empty($sites))
**Affects:** {{ SendInsightAlert::formatPayloadValue($sites) }}

@elseif (isset($payload['delta_pct']))
**Change:** {{ number_format((float) $payload['delta_pct'], 1) }}%

@elseif (isset($payload['current'], $payload['previous']))
**Before → After:** {{ SendInsightAlert::formatPayloadValue($payload['previous']) }} → {{ SendInsightAlert::formatPayloadValue($payload['current']) }}

@endif

**Detected at:** {{ $insight->detected_at?->format('Y-m-d H:i') ?? 'N/A' }} UTC

<x-mail::button :url="route('insights.fix', $insight->id)">
Fix with Claude
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
