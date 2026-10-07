<script setup lang="ts">
// Day-by-day uptime strip fed by UptimeTimelineService::timeline(). Adapted
// from the public status page's strip (Pages/StatusPages/Public.vue) for the
// dark-only private console: no light theme, neutral "up" colour instead of
// the public page's brand-tinted one (colour signals a problem here, not
// identity).
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'

interface DayIncident {
    id: number
    cause: string
    cause_label: string
    started_at: string
    resolved_at: string | null
    duration_seconds: number
    ongoing: boolean
}

interface Day {
    date: string
    uptime: number | null
    status: 'up' | 'partial' | 'down' | 'no_data'
    incidents: DayIncident[]
}

const props = defineProps<{ days: Day[] }>()

const dayFmt = new Intl.DateTimeFormat('en-US', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })
const formatDay = (date: string) => dayFmt.format(new Date(`${date}T00:00:00Z`))

function formatDuration(seconds: number): string {
    if (seconds < 60) return `${Math.max(1, Math.round(seconds))}s`
    const minutes = Math.round(seconds / 60)
    if (minutes < 60) return `${minutes} min`
    const hours = Math.floor(minutes / 60)
    if (hours < 24) return minutes % 60 ? `${hours}h ${minutes % 60}m` : `${hours}h`
    const days = Math.floor(hours / 24)
    return hours % 24 ? `${days}d ${hours % 24}h` : `${days}d`
}

function cellClass(day: Day): string {
    switch (day.status) {
        case 'up':
            return 'bg-zinc-700'
        case 'partial':
            return 'bg-amber-500'
        case 'down':
            return 'bg-red-500'
        default:
            return 'cell-no-data'
    }
}

function dayLabel(day: Day): string {
    const parts = [formatDay(day.date)]
    parts.push(day.uptime === null ? 'no data' : `${day.uptime.toFixed(2)}% uptime`)
    if (day.incidents.length === 0) {
        if (day.uptime !== null) parts.push('no incidents')
    } else {
        for (const i of day.incidents) {
            parts.push(`${i.cause_label}, ${formatDuration(i.duration_seconds)}${i.ongoing ? ' (ongoing)' : ''}`)
        }
    }
    return parts.join(' — ')
}

// One tooltip at a time. Hover, keyboard focus and tap all drive the same state.
const activeIndex = ref<number | null>(null)
const pinned = ref(false)
const focusIndex = reactive({ value: props.days.length - 1 })

const tabIndexFor = (i: number) => (focusIndex.value === i ? 0 : -1)

function show(i: number, isPinned = false) {
    activeIndex.value = i
    pinned.value = isPinned
}

function hide() {
    if (!pinned.value) activeIndex.value = null
}

function toggle(i: number) {
    if (activeIndex.value === i && pinned.value) {
        activeIndex.value = null
        pinned.value = false
    } else {
        show(i, true)
    }
}

function onOutsidePointer(e: PointerEvent) {
    if (!(e.target as HTMLElement | null)?.closest('[data-uptime-strip]')) {
        activeIndex.value = null
        pinned.value = false
    }
}

onMounted(() => document.addEventListener('pointerdown', onOutsidePointer))
onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutsidePointer))

function onKey(e: KeyboardEvent, i: number) {
    const last = props.days.length - 1
    let next = i
    if (e.key === 'ArrowRight') next = Math.min(last, i + 1)
    else if (e.key === 'ArrowLeft') next = Math.max(0, i - 1)
    else if (e.key === 'Home') next = 0
    else if (e.key === 'End') next = last
    else if (e.key === 'Escape') {
        activeIndex.value = null
        pinned.value = false
        return
    } else return

    e.preventDefault()
    focusIndex.value = next
    const strip = (e.currentTarget as HTMLElement).parentElement
    ;(strip?.children[next] as HTMLElement | undefined)?.focus()
}

function onFocus(i: number) {
    focusIndex.value = i
    show(i)
}

const activeDay = computed((): Day | null => (activeIndex.value !== null ? props.days[activeIndex.value] ?? null : null))

// Tooltip anchored above the cell, clamped so it never leaves the strip.
const tipStyle = computed(() => {
    if (activeIndex.value === null) return {}
    const pct = ((activeIndex.value + 0.5) / props.days.length) * 100
    return { left: `clamp(7rem, ${pct}%, calc(100% - 7rem))` }
})
</script>

<template>
    <div class="relative" data-uptime-strip>
        <div
            role="group"
            aria-label="Daily uptime. Use arrow keys to move between days."
            class="flex h-8 gap-[2px]"
            @mouseleave="hide"
        >
            <button
                v-for="(day, i) in days"
                :key="day.date"
                type="button"
                :tabindex="tabIndexFor(i)"
                :aria-label="dayLabel(day)"
                :aria-describedby="activeDay === day ? 'uptime-strip-tip' : undefined"
                class="h-full min-w-0 flex-1 rounded-[1px] transition-opacity focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/60"
                :class="[cellClass(day), activeDay && activeDay !== day ? 'opacity-60' : '']"
                @mouseenter="show(i)"
                @focus="onFocus(i)"
                @blur="hide"
                @click="toggle(i)"
                @keydown="onKey($event, i)"
            />
        </div>

        <div
            v-if="activeDay"
            id="uptime-strip-tip"
            role="tooltip"
            class="pointer-events-none absolute bottom-full z-10 mb-2 w-60 -translate-x-1/2 rounded-md border border-white/10 bg-[var(--color-surface-1)] px-3 py-2.5 text-[13px] text-zinc-200 shadow-lg"
            :style="tipStyle"
        >
            <p class="font-medium">{{ formatDay(activeDay.date) }}</p>
            <p class="mt-0.5 font-mono tabular-nums text-zinc-400">
                {{ activeDay.uptime === null ? 'No data recorded' : `${activeDay.uptime.toFixed(2)}% uptime` }}
            </p>
            <ul v-if="activeDay.incidents.length" class="mt-2 space-y-1 border-t border-white/10 pt-2">
                <li v-for="incident in activeDay.incidents" :key="incident.id" class="flex items-baseline justify-between gap-3">
                    <span class="min-w-0">
                        <span
                            class="mr-1.5 inline-block h-1.5 w-1.5 rounded-[1px] align-middle"
                            :class="incident.ongoing ? 'bg-red-500' : 'bg-amber-500'"
                            aria-hidden="true"
                        />{{ incident.cause_label }}
                    </span>
                    <span class="shrink-0 font-mono tabular-nums text-zinc-400">
                        <template v-if="incident.ongoing">ongoing · </template>{{ formatDuration(incident.duration_seconds) }}
                    </span>
                </li>
            </ul>
            <p v-else-if="activeDay.uptime !== null" class="mt-1 text-zinc-500">No incidents</p>
        </div>
    </div>
</template>

<style scoped>
.cell-no-data {
    background-color: #131417;
    background-image: repeating-linear-gradient(135deg, #25262b 0 1px, transparent 1px 4px);
}
</style>
