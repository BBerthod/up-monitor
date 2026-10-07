<script setup lang="ts">
// Compact daily uptime strip: one bar per day, colour by status. Each bar
// carries a native title tooltip (date + uptime% + incident count) so the
// detail lives on hover instead of eating vertical space on the row.

interface TimelineDay {
    date: string
    status: 'up' | 'partial' | 'down' | 'no_data'
    uptime: number | null
    incidents: number
}

const props = defineProps<{
    days: TimelineDay[]
}>()

const barClass = (day: TimelineDay): string => {
    switch (day.status) {
        case 'up': return 'bg-[var(--color-success)]'
        case 'partial': return 'bg-[var(--color-warning)]'
        case 'down': return 'bg-[var(--color-danger)]'
        default: return 'bg-[var(--color-border)]'
    }
}

const tooltip = (day: TimelineDay): string => {
    const date = new Date(day.date + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
    if (day.status === 'no_data') return `${date} — no data`
    const uptime = day.uptime != null ? `${day.uptime.toFixed(1)}% uptime` : ''
    const incidents = day.incidents > 0 ? `, ${day.incidents} incident${day.incidents > 1 ? 's' : ''}` : ''

    return `${date} — ${uptime}${incidents}`
}
</script>

<template>
    <div class="flex items-end gap-px h-5" role="img" :aria-label="`${days.length}-day uptime strip`">
        <span
            v-for="day in days"
            :key="day.date"
            class="w-1 h-full rounded-[1px]"
            :class="barClass(day)"
            :title="tooltip(day)"
        />
    </div>
</template>
