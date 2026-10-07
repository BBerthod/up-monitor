<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import StatusPageLayout from '@/Layouts/StatusPageLayout.vue'
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'

defineOptions({ layout: StatusPageLayout })

type DayState = 'up' | 'partial' | 'down' | 'no_data'
type OverallStatus = 'operational' | 'degraded' | 'partial_outage' | 'major_outage'

interface DayIncident {
    cause_label: string
    started_at: string
    resolved_at: string | null
    duration_seconds: number
    ongoing: boolean
}

interface Day {
    date: string
    uptime: number | null
    status: DayState
    incidents: DayIncident[]
}

interface Monitor {
    id: number
    name: string
    current_status: 'up' | 'down' | 'unknown'
    response_time_ms: number | null
    uptime_30d: number | null
    uptime_90d: number | null
    measured_days: number
    daily_breakdown: Day[]
}

interface Incident {
    id: number
    monitor_name: string | null
    cause: string
    cause_label: string
    started_at: string
    resolved_at: string | null
    duration_seconds: number
}

const props = defineProps<{
    statusPage: { name: string; description: string | null; theme: 'dark' | 'light' }
    monitors: Monitor[]
    activeIncidents: Incident[]
    pastIncidents: Incident[]
    past_incident_days: number
    overall_status: OverallStatus
    summary: { total: number; down: number }
    days_since_last_incident: number | null
    generated_at: string
    meta: { canonical: string; description: string }
}>()

const isDark = computed(() => props.statusPage.theme !== 'light')

// ─── Theme tokens (local: the status page carries its own light/dark theme) ──

const t = computed(() =>
    isDark.value
        ? {
              panel: 'bg-[#131417] border-[#26272c]',
              divide: 'divide-[#26272c]',
              text: 'text-[#e8e8ea]',
              muted: 'text-[#9a9aa1]',
              faint: 'text-[#6b6b72]',
              label: 'text-[#6b6b72]',
              tip: 'bg-[#1a1b1f] border-[#34353b] text-[#e8e8ea]',
              ring: 'focus-visible:ring-[#e8e8ea]',
          }
        : {
              panel: 'bg-white border-[#e4e4e7]',
              divide: 'divide-[#e4e4e7]',
              text: 'text-[#18181b]',
              muted: 'text-[#52525b]',
              faint: 'text-[#71717a]',
              label: 'text-[#71717a]',
              tip: 'bg-white border-[#d4d4d8] text-[#18181b] shadow-sm',
              ring: 'focus-visible:ring-[#18181b]',
          },
)

// ─── Banner: one sentence, coloured only when something is wrong ────────────

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`

const banner = computed(() => {
    const { total, down } = props.summary
    switch (props.overall_status) {
        case 'major_outage':
            return {
                tone: 'down' as const,
                title: 'Major outage',
                detail: total === 1 ? 'The service is down' : `All ${plural(total, 'service')} are down`,
            }
        case 'partial_outage':
            return {
                tone: 'down' as const,
                title: 'Partial outage',
                detail: `${down} of ${plural(total, 'service')} down`,
            }
        case 'degraded':
            return {
                tone: 'warn' as const,
                title: 'Degraded service',
                detail: `${plural(props.activeIncidents.length, 'incident')} under investigation`,
            }
        default:
            return { tone: 'ok' as const, title: 'All systems operational', detail: null }
    }
})

const bannerClass = computed(() => {
    if (banner.value.tone === 'down') {
        return isDark.value ? 'bg-[#ef4444]/10 border-[#ef4444]/40' : 'bg-[#fef2f2] border-[#fca5a5]'
    }
    if (banner.value.tone === 'warn') {
        return isDark.value ? 'bg-[#f59e0b]/10 border-[#f59e0b]/40' : 'bg-[#fffbeb] border-[#fcd34d]'
    }
    return t.value.panel
})

const bannerIconClass = computed(() => {
    if (banner.value.tone === 'down') return 'text-[#ef4444]'
    if (banner.value.tone === 'warn') return 'text-[#f59e0b]'
    return isDark.value ? 'text-[#10b981]' : 'text-[#059669]'
})

// ─── Formatting ─────────────────────────────────────────────────────────────

const timeFmt = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'UTC' })
const dayFmt = new Intl.DateTimeFormat('en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
const shortDayFmt = new Intl.DateTimeFormat('en-US', { day: 'numeric', month: 'short', timeZone: 'UTC' })

const formatTime = (iso: string) => timeFmt.format(new Date(iso))
const formatDay = (date: string) => dayFmt.format(new Date(`${date}T00:00:00Z`))
const formatShortDay = (iso: string) => shortDayFmt.format(new Date(iso))

function formatDuration(seconds: number): string {
    if (seconds < 60) return `${Math.max(1, Math.round(seconds))}s`
    const minutes = Math.round(seconds / 60)
    if (minutes < 60) return `${minutes} min`
    const hours = Math.floor(minutes / 60)
    if (hours < 24) return minutes % 60 ? `${hours}h ${minutes % 60}m` : `${hours}h`
    const days = Math.floor(hours / 24)
    return hours % 24 ? `${days}d ${hours % 24}h` : `${days}d`
}

const formatUptime = (value: number | null, digits = 2) => (value === null ? '—' : `${value.toFixed(digits)}%`)

// ─── Service status word ────────────────────────────────────────────────────

function statusWord(monitor: Monitor) {
    if (monitor.current_status === 'down') return { label: 'Down', cls: 'text-[#ef4444]' }
    if (monitor.current_status === 'unknown') return { label: 'No data', cls: t.value.faint }
    return { label: 'Operational', cls: t.value.muted }
}

// ─── 90-day strip ───────────────────────────────────────────────────────────
// Mobile shows the last 30 days (cells stay usable by touch), sm+ shows 90.

const MOBILE_DAYS = 30
const isWide = ref(true)
let mql: MediaQueryList | null = null
const syncWidth = () => { isWide.value = mql?.matches ?? true }

onMounted(() => {
    mql = window.matchMedia('(min-width: 640px)')
    syncWidth()
    mql.addEventListener('change', syncWidth)
    document.addEventListener('pointerdown', onOutsidePointer)
})
onBeforeUnmount(() => {
    mql?.removeEventListener('change', syncWidth)
    document.removeEventListener('pointerdown', onOutsidePointer)
})

const firstVisible = (m: Monitor) => (isWide.value ? 0 : Math.max(0, m.daily_breakdown.length - MOBILE_DAYS))

function cellClass(day: Day): string {
    switch (day.status) {
        case 'up':
            return isDark.value ? 'bg-[#10b981]/55' : 'bg-[#10b981]/70'
        case 'partial':
            return 'bg-[#f59e0b]'
        case 'down':
            return 'bg-[#ef4444]'
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
const active = ref<{ monitorId: number; index: number; pinned: boolean } | null>(null)
// Roving tabindex: each strip is a single tab stop, arrows move inside it (default: today).
const focusIndex = reactive<Record<number, number>>({})

const tabIndexFor = (m: Monitor, i: number) => {
    const current = focusIndex[m.id] ?? m.daily_breakdown.length - 1
    return current === i ? 0 : -1
}

function show(m: Monitor, i: number, pinned = false) {
    active.value = { monitorId: m.id, index: i, pinned }
}

function hide(m: Monitor) {
    if (active.value?.monitorId === m.id && !active.value.pinned) active.value = null
}

function toggle(m: Monitor, i: number) {
    const a = active.value
    if (a && a.monitorId === m.id && a.index === i && a.pinned) active.value = null
    else show(m, i, true)
}

function onOutsidePointer(e: PointerEvent) {
    if (!(e.target as HTMLElement | null)?.closest('[data-strip]')) active.value = null
}

function onKey(e: KeyboardEvent, m: Monitor, i: number) {
    const last = m.daily_breakdown.length - 1
    const first = firstVisible(m)
    let next = i
    if (e.key === 'ArrowRight') next = Math.min(last, i + 1)
    else if (e.key === 'ArrowLeft') next = Math.max(first, i - 1)
    else if (e.key === 'Home') next = first
    else if (e.key === 'End') next = last
    else if (e.key === 'Escape') {
        active.value = null
        return
    } else return

    e.preventDefault()
    focusIndex[m.id] = next
    const strip = (e.currentTarget as HTMLElement).parentElement
    ;(strip?.children[next] as HTMLElement | undefined)?.focus()
}

function onFocus(m: Monitor, i: number) {
    focusIndex[m.id] = i
    show(m, i)
}

const activeDay = (m: Monitor): Day | null =>
    active.value?.monitorId === m.id ? m.daily_breakdown[active.value.index] ?? null : null

// Tooltip anchored above the cell, clamped so it never leaves the strip.
function tipStyle(m: Monitor) {
    if (!active.value || active.value.monitorId !== m.id) return {}
    const first = firstVisible(m)
    const count = m.daily_breakdown.length - first
    const pct = ((active.value.index - first + 0.5) / count) * 100
    return { left: `clamp(8rem, ${pct}%, calc(100% - 8rem))` }
}

// ─── Incident summary line ──────────────────────────────────────────────────

// Absence of incidents stated as a fact, over the longest window we can vouch for.
const quietSentence = computed(() => {
    const days = props.days_since_last_incident
    const window = days !== null && days > props.past_incident_days ? days : props.past_incident_days
    return `No incidents reported in the past ${window} days.`
})

// ─── SEO ────────────────────────────────────────────────────────────────────

const pageTitle = computed(() => `${props.statusPage.name} status`)
</script>

<template>
    <Head :title="pageTitle">
        <meta head-key="description" name="description" :content="meta.description" />
        <link head-key="canonical" rel="canonical" :href="meta.canonical" />
        <meta head-key="og:title" property="og:title" :content="pageTitle" />
        <meta head-key="og:description" property="og:description" :content="meta.description" />
        <meta head-key="og:type" property="og:type" content="website" />
        <meta head-key="og:url" property="og:url" :content="meta.canonical" />
    </Head>

    <div class="space-y-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight sm:text-[32px] sm:leading-tight" :class="t.text">{{ statusPage.name }}</h1>
            <p v-if="statusPage.description" class="mt-2 text-[15px]" :class="t.muted">{{ statusPage.description }}</p>
        </header>

        <!-- Overall state: one sentence -->
        <section
            aria-live="polite"
            class="flex items-start gap-3 rounded-md border px-4 py-4 sm:items-center sm:px-5"
            :class="bannerClass"
        >
            <svg v-if="banner.tone === 'ok'" class="mt-0.5 h-5 w-5 shrink-0 sm:mt-0" :class="bannerIconClass" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="8.25" stroke="currentColor" stroke-width="1.5" />
                <path d="M6.5 10.25 8.75 12.5 13.5 7.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else class="mt-0.5 h-5 w-5 shrink-0 sm:mt-0" :class="bannerIconClass" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M10 2.75 18 16.75H2L10 2.75Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                <path d="M10 8v4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" />
                <circle cx="10" cy="14.4" r="0.95" fill="currentColor" />
            </svg>
            <p class="text-base font-medium sm:text-lg" :class="t.text">
                {{ banner.title }}<template v-if="banner.detail"><span :class="t.muted"> — {{ banner.detail }}</span></template>
            </p>
        </section>

        <!-- Active incidents first -->
        <section v-if="activeIncidents.length > 0" aria-labelledby="active-incidents">
            <h2 id="active-incidents" class="mb-3 text-xs font-medium uppercase tracking-[0.08em]" :class="t.label">Active incidents</h2>
            <ul class="divide-y rounded-md border" :class="[t.panel, t.divide]">
                <li v-for="incident in activeIncidents" :key="incident.id" class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                    <div class="min-w-0">
                        <p class="font-medium" :class="t.text">
                            <span class="mr-2 inline-block h-2 w-2 rounded-[2px] bg-[#ef4444] align-middle" aria-hidden="true" />{{ incident.monitor_name }}
                        </p>
                        <p class="mt-0.5 text-sm" :class="t.muted">{{ incident.cause_label }}</p>
                    </div>
                    <p class="shrink-0 font-mono text-[13px] tabular-nums" :class="t.muted">
                        since {{ formatTime(incident.started_at) }} UTC · <span :class="t.text">{{ formatDuration(incident.duration_seconds) }}</span>
                    </p>
                </li>
            </ul>
        </section>

        <!-- Services -->
        <section aria-labelledby="services">
            <div class="mb-3 flex items-baseline justify-between gap-4">
                <h2 id="services" class="text-xs font-medium uppercase tracking-[0.08em]" :class="t.label">Services</h2>
                <p class="text-xs" :class="t.faint">Uptime over the last {{ isWide ? 90 : MOBILE_DAYS }} days</p>
            </div>

            <ul class="divide-y rounded-md border" :class="[t.panel, t.divide]">
                <li v-for="monitor in monitors" :key="monitor.id" class="px-4 py-4 sm:px-5">
                    <div class="flex items-baseline justify-between gap-4">
                        <h3 class="min-w-0 truncate font-medium" :class="t.text" :title="monitor.name">{{ monitor.name }}</h3>
                        <span class="shrink-0 text-sm" :class="statusWord(monitor).cls">{{ statusWord(monitor).label }}</span>
                    </div>

                    <div class="relative mt-3" data-strip>
                        <div
                            role="group"
                            :aria-label="`Daily uptime of ${monitor.name}. Use arrow keys to move between days.`"
                            class="flex h-8 gap-[2px]"
                            @mouseleave="hide(monitor)"
                        >
                            <button
                                v-for="(day, i) in monitor.daily_breakdown"
                                :key="day.date"
                                type="button"
                                :tabindex="tabIndexFor(monitor, i)"
                                :aria-label="dayLabel(day)"
                                :aria-describedby="activeDay(monitor) === day ? `tip-${monitor.id}` : undefined"
                                class="h-full min-w-0 flex-1 rounded-[1px] transition-opacity focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-0"
                                :class="[
                                    cellClass(day),
                                    t.ring,
                                    i < monitor.daily_breakdown.length - MOBILE_DAYS ? 'hidden sm:block' : '',
                                    activeDay(monitor) && activeDay(monitor) !== day ? 'opacity-60' : '',
                                ]"
                                @mouseenter="show(monitor, i)"
                                @focus="onFocus(monitor, i)"
                                @blur="hide(monitor)"
                                @click="toggle(monitor, i)"
                                @keydown="onKey($event, monitor, i)"
                            />
                        </div>

                        <div
                            v-if="activeDay(monitor)"
                            :id="`tip-${monitor.id}`"
                            role="tooltip"
                            class="pointer-events-none absolute bottom-full z-10 mb-2 w-64 -translate-x-1/2 rounded-md border px-3 py-2.5 text-[13px]"
                            :class="t.tip"
                            :style="tipStyle(monitor)"
                        >
                            <p class="font-medium">{{ formatDay(activeDay(monitor)!.date) }}</p>
                            <p class="mt-0.5 font-mono tabular-nums" :class="t.muted">
                                {{ activeDay(monitor)!.uptime === null ? 'No data recorded' : `${activeDay(monitor)!.uptime!.toFixed(2)}% uptime` }}
                            </p>
                            <ul v-if="activeDay(monitor)!.incidents.length" class="mt-2 space-y-1 border-t pt-2" :class="isDark ? 'border-[#34353b]' : 'border-[#e4e4e7]'">
                                <li v-for="(incident, k) in activeDay(monitor)!.incidents" :key="k" class="flex items-baseline justify-between gap-3">
                                    <span class="min-w-0">
                                        <span
                                            class="mr-1.5 inline-block h-1.5 w-1.5 rounded-[1px] align-middle"
                                            :class="incident.ongoing ? 'bg-[#ef4444]' : 'bg-[#f59e0b]'"
                                            aria-hidden="true"
                                        />{{ incident.cause_label }}
                                    </span>
                                    <span class="shrink-0 font-mono tabular-nums" :class="t.muted"><template v-if="incident.ongoing">ongoing · </template>{{ formatDuration(incident.duration_seconds) }}</span>
                                </li>
                            </ul>
                            <p v-else-if="activeDay(monitor)!.uptime !== null" class="mt-1" :class="t.faint">No incidents</p>
                        </div>
                    </div>

                    <div class="mt-2 flex items-center justify-between gap-3 text-xs" :class="t.faint">
                        <span>{{ isWide ? 90 : MOBILE_DAYS }} days ago</span>
                        <span class="font-mono tabular-nums">
                            <span :title="'Uptime over the last 30 days'">30d <span :class="t.muted">{{ formatUptime(monitor.uptime_30d) }}</span></span>
                            <span class="mx-2" aria-hidden="true">·</span>
                            <span :title="monitor.measured_days < 90 ? `Measured over ${monitor.measured_days} days of data` : 'Uptime over the last 90 days'">
                                90d <span :class="t.muted">{{ formatUptime(monitor.uptime_90d) }}</span>
                            </span>
                        </span>
                        <span>Today</span>
                    </div>
                </li>
            </ul>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs" :class="t.faint" aria-hidden="true">
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-[1px]" :class="isDark ? 'bg-[#10b981]/55' : 'bg-[#10b981]/70'" />No issues</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-[1px] bg-[#f59e0b]" />Incident</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-[1px] bg-[#ef4444]" />Outage</span>
                <span class="inline-flex items-center gap-1.5"><span class="cell-no-data h-2.5 w-2.5 rounded-[1px]" />No data</span>
            </div>
        </section>

        <!-- Past incidents -->
        <section aria-labelledby="past-incidents">
            <div class="mb-3 flex items-baseline justify-between gap-4">
                <h2 id="past-incidents" class="text-xs font-medium uppercase tracking-[0.08em]" :class="t.label">Past incidents</h2>
                <p v-if="pastIncidents.length" class="text-xs" :class="t.faint">Last {{ past_incident_days }} days</p>
            </div>

            <ul v-if="pastIncidents.length" class="divide-y rounded-md border" :class="[t.panel, t.divide]">
                <li v-for="incident in pastIncidents" :key="incident.id" class="grid gap-1 px-4 py-3 sm:grid-cols-[5.5rem_1fr_auto] sm:items-baseline sm:gap-4">
                    <time :datetime="incident.started_at" class="font-mono text-[13px] tabular-nums" :class="t.muted">{{ formatShortDay(incident.started_at) }}</time>
                    <p class="min-w-0">
                        <span class="font-medium" :class="t.text">{{ incident.monitor_name }}</span>
                        <span :class="t.muted"> — {{ incident.cause_label }}</span>
                    </p>
                    <p class="font-mono text-[13px] tabular-nums" :class="t.muted">
                        {{ formatTime(incident.started_at) }}–{{ formatTime(incident.resolved_at!) }} UTC · <span :class="t.text">{{ formatDuration(incident.duration_seconds) }}</span>
                    </p>
                </li>
            </ul>
            <p v-else class="rounded-md border px-4 py-4 text-sm" :class="[t.panel, t.muted]">
                {{ activeIncidents.length ? `No resolved incidents in the past ${past_incident_days} days.` : quietSentence }}
            </p>
        </section>
    </div>
</template>

<style>
/* Days without any check (monitor not created yet, pruned data): a faint hatch,
   clearly different from a measured day. Unscoped: keyed on the layout's theme class. */
.status-page .cell-no-data {
    background-color: var(--nd-bg);
    background-image: repeating-linear-gradient(135deg, var(--nd-line) 0 1px, transparent 1px 4px);
}
.status-dark .cell-no-data {
    --nd-bg: #131417;
    --nd-line: #25262b;
}
.status-light .cell-no-data {
    --nd-bg: #fafafa;
    --nd-line: #e0e0e4;
}
</style>
