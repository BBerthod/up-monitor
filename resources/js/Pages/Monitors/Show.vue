<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import { useFocusTrap } from '@/Composables/useFocusTrap'
import { usePageLoading } from '@/Composables/usePageLoading'
import LatencyHeatmap from '@/Components/LatencyHeatmap.vue'
import LighthouseHistory from '@/Components/LighthouseHistory.vue'
import LighthouseScores from '@/Components/LighthouseScores.vue'
import ResponseTimeChart from '@/Components/ResponseTimeChart.vue'
import BackLink from '@/Components/BackLink.vue'
import GlassCard from '@/Components/GlassCard.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import CopyButton from '@/Components/CopyButton.vue'
import SkeletonMonitorShow from '@/Components/SkeletonMonitorShow.vue'
import PurgeDialog from '@/Components/PurgeDialog.vue'
import FunctionalChecks from '@/Components/FunctionalChecks.vue'
import SeverityBadge from '@/Components/SeverityBadge.vue'
import Icon from '@/Components/Icon.vue'
import type { IconName } from '@/Components/Icon.vue'
import StrikingDistanceList from '@/Components/StrikingDistanceList.vue'
import ContextualInsights from '@/Components/Triage/ContextualInsights.vue'
import type { TriageItemData } from '@/Components/Triage/ContextualInsights.vue'
import UptimeTimelineStrip from '@/Components/UptimeTimelineStrip.vue'


const { isLoading } = usePageLoading()

interface Check { id: number; status: 'up' | 'down'; response_time_ms: number; status_code: number; checked_at: string }
interface Incident { id: number; started_at: string; resolved_at: string | null; cause: string; severity?: string | null; notes?: string | null }
// LengthAwarePaginator serialised as-is: the page keys sit at the top level
// (there is no `meta` wrapper, that shape belongs to API resources).
interface PaginatedIncidents {
    data: Incident[]
    current_page: number
    last_page: number
    per_page: number
    from: number | null
    to: number | null
    total: number
}
interface IncidentStats {
    total: number
    active: number
    active_incident: { id: number; started_at: string; cause: string; cause_label: string } | null
    mttr_minutes: number
    downtime_30d_minutes: number
}
interface TimelineDay {
    date: string
    uptime: number | null
    status: 'up' | 'partial' | 'down' | 'no_data'
    incidents: Array<{ id: number; cause: string; cause_label: string; started_at: string; resolved_at: string | null; duration_seconds: number; ongoing: boolean }>
}
interface Timeline {
    days: TimelineDay[]
    uptime_30d: number | null
    uptime_90d: number | null
    measured_days: number
}
interface NotificationChannel { id: number; name: string; type: string }
interface StrikingOpportunity {
    id: number
    title: string
    impact_score: number
    query: string | null
    page: string | null
    position: number | null
    impressions: number | null
    ctr: number | null
    estimated_gain: number | null
}
interface Monitor {
    id: number
    name: string
    url: string
    type: 'http' | 'ping' | 'port' | 'dns'
    method?: string
    expected_status_code?: number | null
    keyword?: string | null
    is_active: boolean
    is_paused?: boolean
    interval_seconds: number
    timeout_seconds: number
    alert_after_failures: number
    badge_secret?: string | null
    notification_channels: NotificationChannel[]
    status_pages?: { id: number; name: string }[]
}

const props = defineProps<{
    monitor: Monitor
    checks: Check[]
    lastCheckedAt: string | null
    incidents: PaginatedIncidents
    incidentStats: IncidentStats
    incidentTimeline: Incident[]
    incidentSort: string
    incidentDir: string
    uptime: { day: number; week: number; month: number }
    timeline: Timeline | null
    heatmapData: Record<string, number>
    heatmapDays: number
    lighthouseScore: { performance: number; accessibility: number; best_practices: number; seo: number; lcp: number | null; fcp: number | null; cls: number | null; tbt: number | null; speed_index: number | null; scored_at: string } | null
    lighthouseHistory?: Array<any> | null
    chartData: Array<any>
    currentPeriod: string
    functionalChecks: Array<any>
    strikingDistance: StrikingOpportunity[]
    insights?: TriageItemData[] | null
}>()

useRealtimeUpdates({
    // Scoped to THIS monitor: the channel is team-wide, and without the
    // filter every sibling monitor's check reloaded these eight props.
    monitorId: props.monitor.id,
    onMonitorChecked: ['monitor', 'checks', 'lastCheckedAt', 'chartData', 'uptime', 'timeline', 'heatmapData', 'incidents', 'incidentStats', 'incidentTimeline'],
    onLighthouseCompleted: ['lighthouseScore', 'lighthouseHistory'],
    onInsightChanged: ['insights'],
})

const baseUrl = computed(() => typeof window !== 'undefined' ? window.location.origin : '')
const pauseForm = useForm({})

const showChannelModal = ref(false)
const channelModalRef = ref<HTMLElement | null>(null)
const selectedChannel = ref<{ id: number; name: string; type: string } | null>(null)
const showDeleteDialog = ref(false)
const showPurgeChecks = ref(false)
const showPurgeIncidents = ref(false)
const showPurgeLighthouse = ref(false)

// Incident notes
const editingNotesId = ref<number | null>(null)
const notesInput = ref('')

const openNotesEditor = (incident: Incident) => {
    editingNotesId.value = incident.id
    notesInput.value = incident.notes ?? ''
}

const cancelNotesEditor = () => {
    editingNotesId.value = null
    notesInput.value = ''
}

const savingNotesId = ref<number | null>(null)

const saveNotes = (incidentId: number) => {
    if (savingNotesId.value !== null) return
    savingNotesId.value = incidentId
    router.put(route('incidents.update', incidentId), { notes: notesInput.value }, {
        preserveScroll: true,
        onSuccess: () => {
            editingNotesId.value = null
            notesInput.value = ''
        },
        onFinish: () => {
            savingNotesId.value = null
        },
    })
}

useFocusTrap(channelModalRef, showChannelModal)

const monitorStatus = computed((): 'up' | 'down' | 'paused' => {
    if (!props.monitor.is_active) return 'paused'
    return props.checks[0]?.status === 'up' ? 'up' : 'down'
})

// "42s ago" precision: useTimeAgo's minute granularity is too coarse for a
// monitor that gets checked every 30-60s.
const timeAgoPrecise = (iso: string | null): string => {
    if (!iso) return 'never'
    const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000))
    if (seconds < 60) return `${seconds}s ago`
    const minutes = Math.floor(seconds / 60)
    if (minutes < 90) return `${minutes}m ago`
    const hours = Math.floor(minutes / 60)
    if (hours < 48) return `${hours}h ago`
    return `${Math.floor(hours / 24)}d ago`
}

// One-sentence status: the reason it's down when it's down, otherwise the
// reassurance that it's being watched and how well it's doing.
const statusSentence = computed(() => {
    const activeIncident = props.incidentStats.active_incident
    if (monitorStatus.value === 'paused') return 'Paused — not being checked.'
    if (monitorStatus.value === 'down' && activeIncident) {
        return `Down for ${duration(activeIncident.started_at, null)} · ${activeIncident.cause_label}`
    }
    if (monitorStatus.value === 'down') return 'Down.'
    return `Up · last checked ${timeAgoPrecise(props.lastCheckedAt)} · ${props.uptime.week.toFixed(1)}% over 7 days`
})

const togglePause = () => {
    if (props.monitor.is_active) {
        pauseForm.post(route('monitors.pause', props.monitor.id))
    } else {
        pauseForm.post(route('monitors.resume', props.monitor.id))
    }
}

const confirmDelete = () => {
    router.delete(route('monitors.destroy', props.monitor.id))
}

const formatDate = (d: string) => new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })

const duration = (start: string, end: string | null): string => {
    const ms = (end ? new Date(end).getTime() : Date.now()) - new Date(start).getTime()
    const h = Math.floor(ms / 3600000), m = Math.floor((ms % 3600000) / 60000)
    return h > 24 ? `${Math.floor(h / 24)}d ${h % 24}h` : `${h}h ${m}m`
}

const formatMttr = (minutes: number): string => {
    if (minutes === 0) return '-'
    if (minutes < 60) return `${minutes}m`
    const h = Math.floor(minutes / 60), m = minutes % 60
    return m > 0 ? `${h}h ${m}m` : `${h}h`
}

const formatDowntime = (minutes: number): string => {
    if (minutes === 0) return '0m'
    if (minutes < 60) return `${minutes}m`
    const h = Math.floor(minutes / 60), m = minutes % 60
    return m > 0 ? `${h}h ${m}m` : `${h}h`
}

const avgMs = computed(() => {
    if (!props.checks.length) return 0
    return Math.round(props.checks.reduce((s, c) => s + c.response_time_ms, 0) / props.checks.length)
})

// Colour signals a problem, not quality: a healthy figure stays neutral, only
// degraded/bad readings get amber/red.
const uptimeColor = (v: number) => v > 99 ? 'text-slate-300' : v > 95 ? 'text-yellow-400' : 'text-red-400'

const handlePeriodChange = (period: string) => {
    router.visit(route('monitors.show', props.monitor.id) + '?period=' + period, {
        only: ['chartData', 'currentPeriod'],
        preserveState: true,
        preserveScroll: true,
    })
}

const openChannelModal = (channel: { id: number; name: string; type: string }) => {
    selectedChannel.value = channel
    showChannelModal.value = true
}

const closeChannelModal = () => {
    showChannelModal.value = false
    selectedChannel.value = null
}

const badgeMarkdown = computed(() => `![Uptime](${baseUrl.value}/badge/${props.monitor.badge_secret}.svg)`)

const channelIcons: Record<string, string> = {
    email: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
    slack: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 4l4 4-4 4M6 10h.01M17 14h.01"/>',
    discord: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>',
    webhook: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>',
    telegram: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>',
    push: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
}

// Incident cause: a neutral label + discreet icon. Colour is reserved for
// severity (SeverityBadge) — a cause isn't itself worse or better, it's just
// what happened, so a Timeout badge shouldn't compete visually with a
// Critical severity badge in the same row.
const causeConfig: Record<string, { label: string; icon: string }> = {
    timeout: {
        label: 'Timeout',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    },
    status_code: {
        label: 'Status Code',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',
    },
    ssl: {
        label: 'SSL Error',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
    },
    keyword: {
        label: 'Keyword',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>',
    },
    error: {
        label: 'Error',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    },
}

const causeBadgeCls = 'text-zinc-300 bg-white/5 border border-white/10'
const getCauseConfig = (cause: string) => causeConfig[cause] ?? { label: cause.replace(/_/g, ' '), icon: '' }

// Incident sorting
const handleIncidentSort = (column: string) => {
    const newDir = props.incidentSort === column && props.incidentDir === 'desc' ? 'asc' : 'desc'
    router.visit(route('monitors.show', props.monitor.id), {
        data: { incident_sort: column, incident_dir: newDir, period: props.currentPeriod },
        only: ['incidents', 'incidentSort', 'incidentDir'],
        preserveState: true,
        preserveScroll: true,
    })
}

const sortIcon = (column: string): IconName => {
    if (props.incidentSort !== column) return 'sort'
    return props.incidentDir === 'asc' ? 'sort-asc' : 'sort-desc'
}

// Incident pagination
const handleIncidentPage = (page: number) => {
    router.visit(route('monitors.show', props.monitor.id), {
        data: { incident_page: page, incident_sort: props.incidentSort, incident_dir: props.incidentDir, period: props.currentPeriod },
        only: ['incidents'],
        preserveState: true,
        preserveScroll: true,
    })
}

// Visible page numbers with ellipsis markers (null = ellipsis)
const visiblePages = computed((): (number | null)[] => {
    const current = props.incidents.current_page
    const last = props.incidents.last_page
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)
    const pageSet = new Set([1, last, current, current - 1, current + 1].filter(p => p >= 1 && p <= last))
    const sorted = Array.from(pageSet).sort((a, b) => a - b)
    const result: (number | null)[] = []
    for (let i = 0; i < sorted.length; i++) {
        if (i > 0 && sorted[i] - sorted[i - 1] > 1) result.push(null)
        result.push(sorted[i])
    }
    return result
})
</script>

<template>
    <Head :title="monitor.name" />

    <SkeletonMonitorShow v-if="isLoading" />

    <div v-else class="space-y-6">
        <BackLink :href="route('monitors.index')" label="Back to Monitors" />

        <!-- Ongoing incident banner -->
        <Transition name="slide-down">
            <div v-if="incidentStats.active > 0 && incidentStats.active_incident" class="flex items-center gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300">
                <span class="relative flex h-2.5 w-2.5 shrink-0">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                </span>
                <span class="font-medium text-red-200">Incident in progress</span>
                <span class="text-sm text-red-400">
                    {{ getCauseConfig(incidentStats.active_incident.cause).label }} · since {{ formatDate(incidentStats.active_incident.started_at) }}
                </span>
            </div>
        </Transition>

        <!-- Copilot contextual insights (hidden when empty) -->
        <ContextualInsights
            :items="props.insights ?? []"
            :title="`Copilot — ${monitor.name}`"
            storage-key="ctx-insights.monitors"
            @changed="router.reload({ only: ['insights'] })"
        />

        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold text-white">{{ monitor.name }}</h1>
                    <StatusBadge :status="monitorStatus" size="md" />
                </div>
                <!-- One-sentence status: the answer before the evidence -->
                <p class="mt-1 text-sm" :class="monitorStatus === 'down' ? 'text-red-400' : 'text-slate-400'">{{ statusSentence }}</p>
                <div class="flex items-center gap-2 mt-1">
                    <p class="text-slate-500 text-sm truncate">{{ monitor.url }}</p>
                    <CopyButton :text="monitor.url" />
                </div>
                <div v-if="monitor.notification_channels?.length" class="flex flex-wrap items-center gap-2 mt-2">
                    <button v-for="ch in monitor.notification_channels" :key="ch.id" @click="openChannelModal(ch)" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs bg-white/5 border border-white/10 text-slate-300 hover:text-white hover:bg-white/10 hover:border-white/20 transition-colors cursor-pointer" :aria-label="`View ${ch.name} channel details`">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="channelIcons[ch.type] || channelIcons.webhook" />
                        <span>{{ ch.name }}</span>
                    </button>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <Link :href="route('monitors.edit', monitor.id)" aria-label="Edit monitor settings" class="px-4 py-2 rounded-lg text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors">Edit</Link>
                <button @click="togglePause" :disabled="pauseForm.processing" :aria-label="monitor.is_active ? 'Pause monitoring' : 'Resume monitoring'" class="px-4 py-2 rounded-lg text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">{{ monitor.is_active ? 'Pause' : 'Resume' }}</button>
                <button @click="showDeleteDialog = true" aria-label="Delete this monitor" class="px-4 py-2 rounded-lg text-red-400 hover:text-red-300 bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 transition-colors">Delete</button>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
            <div class="glass p-4">
                <p class="text-slate-400 text-sm mb-1">Status</p>
                <div class="flex items-center gap-2">
                    <div :class="['status-dot', checks[0]?.status === 'up' ? 'online' : 'offline']" />
                    <span class="text-lg font-semibold text-white">{{ checks[0]?.status?.toUpperCase() || 'UNKNOWN' }}</span>
                </div>
            </div>
            <div class="glass p-4">
                <p class="text-slate-400 text-sm mb-1">Last Checked</p>
                <p class="text-lg font-semibold text-white font-mono">{{ timeAgoPrecise(lastCheckedAt) }}</p>
            </div>
            <div class="glass p-4 col-span-2 sm:col-span-1">
                <p class="text-slate-400 text-sm mb-1">Uptime</p>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 font-mono text-sm">
                    <span :class="uptimeColor(uptime.day)">24h {{ uptime.day.toFixed(1) }}%</span>
                    <span :class="uptimeColor(uptime.week)">7d {{ uptime.week.toFixed(1) }}%</span>
                    <span :class="uptimeColor(uptime.month)">30d {{ uptime.month.toFixed(1) }}%</span>
                </div>
            </div>
            <div class="glass p-4">
                <p class="text-slate-400 text-sm mb-1">Avg Response</p>
                <p class="text-lg font-semibold text-white font-mono">{{ avgMs }}<span class="text-sm text-slate-400">ms</span></p>
            </div>
            <div class="glass p-4">
                <p class="text-slate-400 text-sm mb-1">Active Incidents</p>
                <p class="text-lg font-semibold font-mono" :class="incidentStats.active > 0 ? 'text-red-400' : 'text-slate-300'">{{ incidentStats.active }}</p>
            </div>
        </div>

        <!-- 90-day uptime timeline -->
        <GlassCard v-if="timeline" :title="`Uptime (last ${timeline.measured_days} of 90 days)`">
            <template #actions>
                <p class="text-xs text-slate-500 font-mono">
                    30d <span class="text-slate-300">{{ timeline.uptime_30d !== null ? timeline.uptime_30d.toFixed(2) + '%' : '—' }}</span>
                    <span class="mx-1.5">·</span>
                    90d <span class="text-slate-300">{{ timeline.uptime_90d !== null ? timeline.uptime_90d.toFixed(2) + '%' : '—' }}</span>
                </p>
            </template>
            <UptimeTimelineStrip :days="timeline.days" />
        </GlassCard>

        <!-- Incident History -->
        <GlassCard title="Incident History">
            <template #actions>
                <button @click="showPurgeIncidents = true" class="px-3 py-1.5 rounded-lg text-xs text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Purge
                </button>
            </template>
            <!-- Stats row (solution 3) -->
            <div class="grid grid-cols-3 gap-4 mb-6 p-4 rounded-lg bg-white/[0.03] border border-white/5">
                <div class="text-center">
                    <p class="text-2xl font-bold font-mono text-white">{{ incidentStats.total }}</p>
                    <p class="text-xs text-slate-500 mt-0.5 uppercase tracking-wider">Total incidents</p>
                </div>
                <div class="text-center border-x border-white/5">
                    <p class="text-2xl font-bold font-mono" :class="incidentStats.mttr_minutes > 0 ? 'text-yellow-400' : 'text-slate-300'">
                        {{ formatMttr(incidentStats.mttr_minutes) }}
                    </p>
                    <p class="text-xs text-slate-500 mt-0.5 uppercase tracking-wider">Avg resolution</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold font-mono" :class="incidentStats.downtime_30d_minutes > 0 ? 'text-red-400' : 'text-slate-300'">
                        {{ formatDowntime(incidentStats.downtime_30d_minutes) }}
                    </p>
                    <p class="text-xs text-slate-500 mt-0.5 uppercase tracking-wider">Downtime (30d)</p>
                </div>
            </div>

            <!-- Table -->
            <div v-if="incidents.data.length > 0" class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-left text-slate-400 text-sm border-b border-white/10">
                            <!-- Sortable: cause -->
                            <th class="pb-3 font-medium">
                                <button @click="handleIncidentSort('cause')" class="inline-flex items-center gap-1.5 hover:text-white transition-colors">
                                    Cause
                                    <Icon :name="sortIcon('cause')" :size="12" />
                                </button>
                            </th>
                            <th class="pb-3 font-medium">Severity</th>
                            <!-- Sortable: started_at -->
                            <th class="pb-3 font-medium">
                                <button @click="handleIncidentSort('started_at')" class="inline-flex items-center gap-1.5 hover:text-white transition-colors">
                                    Started
                                    <Icon :name="sortIcon('started_at')" :size="12" />
                                </button>
                            </th>
                            <!-- Sortable: resolved_at -->
                            <th class="pb-3 font-medium">
                                <button @click="handleIncidentSort('resolved_at')" class="inline-flex items-center gap-1.5 hover:text-white transition-colors">
                                    Resolved
                                    <Icon :name="sortIcon('resolved_at')" :size="12" />
                                </button>
                            </th>
                            <th class="pb-3 font-medium">Duration</th>
                            <th class="pb-3 font-medium">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="incident in incidents.data" :key="incident.id" class="border-b border-white/5 last:border-0 align-top">
                            <td class="py-3">
                                <span :class="['inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium', causeBadgeCls]">
                                    <svg v-if="getCauseConfig(incident.cause).icon" class="w-3 h-3 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="getCauseConfig(incident.cause).icon" />
                                    {{ getCauseConfig(incident.cause).label }}
                                </span>
                            </td>
                            <td class="py-3">
                                <SeverityBadge v-if="incident.severity" :severity="incident.severity" />
                                <span v-else class="text-xs text-slate-600">-</span>
                            </td>
                            <td class="py-3 text-slate-300 text-sm">{{ formatDate(incident.started_at) }}</td>
                            <td class="py-3 text-sm">
                                <span v-if="incident.resolved_at" class="text-slate-300">{{ formatDate(incident.resolved_at) }}</span>
                                <span v-else class="inline-flex items-center gap-1.5 text-red-400 font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-pulse"></span>
                                    Ongoing
                                </span>
                            </td>
                            <td class="py-3 text-slate-400 text-sm font-mono">{{ duration(incident.started_at, incident.resolved_at) }}</td>
                            <!-- Notes cell (resolved incidents only) -->
                            <td class="py-3 max-w-[200px]">
                                <template v-if="incident.resolved_at">
                                    <div v-if="editingNotesId !== incident.id">
                                        <p v-if="incident.notes" class="text-xs text-slate-400 leading-relaxed mb-1 line-clamp-2">{{ incident.notes }}</p>
                                        <button
                                            @click="openNotesEditor(incident)"
                                            class="text-xs text-zinc-600 hover:text-emerald-400 transition-colors"
                                        >
                                            {{ incident.notes ? 'Edit notes' : '+ Add notes' }}
                                        </button>
                                    </div>
                                    <div v-else class="space-y-1.5">
                                        <textarea
                                            v-model="notesInput"
                                            rows="3"
                                            placeholder="Post-mortem notes..."
                                            class="w-full text-xs bg-white/5 border border-white/10 rounded-lg px-2.5 py-2 text-white placeholder-zinc-600 focus:outline-none focus:border-emerald-500/50 resize-none transition-colors"
                                            @keydown.escape="cancelNotesEditor"
                                        />
                                        <div class="flex items-center gap-2">
                                            <button
                                                @click="saveNotes(incident.id)"
                                                :disabled="savingNotesId === incident.id"
                                                class="text-xs px-2 py-1 rounded bg-emerald-500/20 text-emerald-400 hover:bg-emerald-500/30 border border-emerald-500/20 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                {{ savingNotesId === incident.id ? 'Saving…' : 'Save' }}
                                            </button>
                                            <button
                                                @click="cancelNotesEditor"
                                                class="text-xs px-2 py-1 rounded text-zinc-500 hover:text-zinc-300 transition-colors"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </template>
                                <span v-else class="text-xs text-zinc-700">-</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination (solution 4) -->
                <div v-if="incidents.last_page > 1" class="flex items-center justify-between mt-4 pt-4 border-t border-white/5">
                    <p class="text-sm text-slate-500">
                        {{ incidents.from }}–{{ incidents.to }} of {{ incidents.total }} incidents
                    </p>
                    <div class="flex items-center gap-1">
                        <button
                            @click="handleIncidentPage(incidents.current_page - 1)"
                            :disabled="incidents.current_page === 1"
                            class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                            aria-label="Previous page"
                        >
                            <Icon name="chevron-left" :size="14" />
                        </button>

                        <template v-for="(page, i) in visiblePages" :key="page ?? `ellipsis-${i}`">
                            <span v-if="page === null" class="text-slate-600 px-1">…</span>
                            <button
                                v-else
                                @click="handleIncidentPage(page)"
                                :class="['w-8 h-8 rounded-lg text-sm transition-colors', page === incidents.current_page ? 'bg-white/10 text-white font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5']"
                            >
                                {{ page }}
                            </button>
                        </template>

                        <button
                            @click="handleIncidentPage(incidents.current_page + 1)"
                            :disabled="incidents.current_page === incidents.last_page"
                            class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                            aria-label="Next page"
                        >
                            <Icon name="chevron-right" :size="14" />
                        </button>
                    </div>
                </div>
            </div>
            <p v-else class="text-slate-500 text-center py-8">No incidents recorded</p>
        </GlassCard>

        <ResponseTimeChart :chart-data="chartData" :current-period="currentPeriod" :monitor-id="monitor.id" @period-change="handlePeriodChange">
            <template #actions>
                <button @click="showPurgeChecks = true" class="px-3 py-1.5 rounded-lg text-xs text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Purge
                </button>
            </template>
        </ResponseTimeChart>

        <GlassCard :title="`Latency Heatmap (last ${heatmapDays} days)`">
            <div class="overflow-x-auto">
                <LatencyHeatmap :data="heatmapData" :days="heatmapDays" />
            </div>
        </GlassCard>

        <GlassCard title="Lighthouse Scores">
            <template #actions>
                <button @click="showPurgeLighthouse = true" class="px-3 py-1.5 rounded-lg text-xs text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Purge
                </button>
            </template>
            <LighthouseScores :scores="lighthouseScore" :monitor-id="monitor.id" :monitor-type="monitor.type" />
        </GlassCard>

        <GlassCard v-if="monitor.type === 'http'">
            <LighthouseHistory :history="lighthouseHistory ?? null" :monitor-id="monitor.id" />
        </GlassCard>

        <StrikingDistanceList v-if="monitor.type === 'http'" :opportunities="strikingDistance" />


        <!-- Functional Checks Section -->
        <GlassCard title="Functional Checks">
            <FunctionalChecks :monitor-id="monitor.id" :checks="functionalChecks" />
        </GlassCard>

        <GlassCard v-if="monitor.badge_secret" title="Status Badge">
            <div class="flex flex-wrap items-center gap-4">
                <img :src="`/badge/${monitor.badge_secret}.svg`" :alt="`${monitor.name} uptime badge`" />
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <code class="min-w-0 flex-1 overflow-x-auto whitespace-nowrap text-sm text-slate-400 font-mono bg-white/5 px-3 py-2 rounded">{{ badgeMarkdown }}</code>
                    <CopyButton :text="badgeMarkdown" />
                </div>
            </div>
        </GlassCard>
    </div>

    <PurgeDialog
        v-model:show="showPurgeChecks"
        :monitor-id="monitor.id"
        :monitor-name="monitor.name"
        target="checks"
    />
    <PurgeDialog
        v-model:show="showPurgeIncidents"
        :monitor-id="monitor.id"
        :monitor-name="monitor.name"
        target="incidents"
    />
    <PurgeDialog
        v-model:show="showPurgeLighthouse"
        :monitor-id="monitor.id"
        :monitor-name="monitor.name"
        target="lighthouse"
    />

    <ConfirmDialog
        v-model:show="showDeleteDialog"
        title="Delete Monitor"
        :message="`Are you sure you want to delete '${monitor.name}'? This action cannot be undone.`"
        confirm-label="Delete"
        variant="danger"
        @confirm="confirmDelete"
    />

    <Teleport to="body">
        <Transition name="fade">
            <div v-if="showChannelModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="closeChannelModal">
                <div class="absolute inset-0 bg-black/60" @click="closeChannelModal" />
                <div ref="channelModalRef" class="relative bg-[var(--color-surface-0)] border border-white/10 rounded-xl p-6 w-full max-w-sm shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="channel-modal-title">
                    <button @click="closeChannelModal" aria-label="Close dialog" class="absolute top-4 right-4 text-slate-400 hover:text-white transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                    <div v-if="selectedChannel" class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center">
                            <svg class="w-5 h-5 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="channelIcons[selectedChannel.type] || channelIcons.webhook" />
                        </div>
                        <div>
                            <h3 id="channel-modal-title" class="text-lg font-semibold text-white">{{ selectedChannel.name }}</h3>
                            <p class="text-sm text-slate-400 uppercase">{{ selectedChannel.type }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.slide-down-enter-active,
.slide-down-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>
