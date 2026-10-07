<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import PageHeader from '@/Components/PageHeader.vue'
import MetricGauge from '@/Components/MetricGauge.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import ContextualInsights from '@/Components/Triage/ContextualInsights.vue'
import type { TriageItemData } from '@/Components/Triage/ContextualInsights.vue'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface Thresholds {
    disk_warning: number
    disk_critical: number
    ram_warning: number
    ram_critical: number
    cpu_warning: number
    cpu_critical: number
    cpu_sustained_points: number
    load_cores: number | null
    load_warning: number
    load_critical: number
}

interface Server {
    id: number
    name: string
    dokploy_server_id: string | null
    is_active: boolean
    has_monitoring: boolean
    has_ingest_token: boolean
    thresholds: Thresholds
}

interface LatestMetric {
    cpu: number
    ram: number
    disk: number
    ram_used_mb: number
    ram_total_mb: number
    disk_used_gb: number
    disk_total_gb: number
    load_avg_1: number | null
    load_avg_5: number | null
    load_avg_15: number | null
    captured_at: string | null
}

interface HistoryPoint {
    t: string
    cpu: number
    ram: number
    disk: number
    load1: number | null
}

interface SiteRef {
    id: number
    primary_domain: string
}

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

const props = defineProps<{
    server: Server
    latest: LatestMetric | null
    history: HistoryPoint[]
    sites: SiteRef[]
    insights?: TriageItemData[] | null
    period: '24h' | '7d' | '30d'
}>()

useRealtimeUpdates({
    // Scoped to THIS server, and `history` is deliberately NOT refreshed: a
    // metric lands every five minutes, and each refresh used to re-transfer
    // the whole series. The gauges (`latest`) update live; the charts refresh
    // when the period selector triggers its own partial reload.
    serverId: props.server.id,
    onServerMetricReceived: ['server', 'latest', 'insights'],
    onInsightChanged: ['insights'],
})

// ---------------------------------------------------------------------------
// Flash: serverToken is outside the typed Flash interface, read directly
// ---------------------------------------------------------------------------

const page = usePage()
const serverToken = computed(() => (page.props as any).flash?.serverToken as string | undefined)

// ---------------------------------------------------------------------------
// Delete dialog
// ---------------------------------------------------------------------------

const showDeleteDialog = ref(false)

const handleDelete = () => {
    router.delete(route('servers.destroy', props.server.id))
}

// ---------------------------------------------------------------------------
// Token UI
// ---------------------------------------------------------------------------

const tokenVisible = ref(false)
const tokenCopied = ref(false)
const rotatingToken = ref(false)

const copyToken = async () => {
    if (!serverToken.value) return
    await navigator.clipboard.writeText(serverToken.value)
    tokenCopied.value = true
    setTimeout(() => { tokenCopied.value = false }, 2000)
}

const maskToken = (token: string) => token.slice(0, 8) + '•'.repeat(20) + token.slice(-8)

const rotateToken = () => {
    rotatingToken.value = true
    router.post(route('servers.rotate-token', props.server.id), {}, {
        onFinish: () => { rotatingToken.value = false },
    })
}

// ---------------------------------------------------------------------------
// History chart
// ---------------------------------------------------------------------------

type Period = '30d' | '7d' | '24h'

// The period is resolved server-side: raw 5-minute points for 24h, hourly /
// 6-hourly SQL averages for 7d/30d. Switching only reloads the `history`
// prop instead of shipping 30 raw days and filtering in the browser.
const activePeriod = computed<Period>(() => props.period)

const periods: { label: string; value: Period }[] = [
    { label: '30d', value: '30d' },
    { label: '7d', value: '7d' },
    { label: '24h', value: '24h' },
]

const switchPeriod = (value: Period) => {
    if (value === props.period) return
    router.get(route('servers.show', props.server.id), { period: value }, {
        only: ['history', 'period'],
        preserveState: true,
        preserveScroll: true,
    })
}

const filteredHistory = computed<HistoryPoint[]>(() => props.history)

// ---------------------------------------------------------------------------
// SVG chart helpers
// ---------------------------------------------------------------------------

const VIEW_W = 800
const VIEW_H = 180
const PAD_L = 40
const PAD_R = 10
const PAD_T = 12
const PAD_B = 28
const CHART_W = VIEW_W - PAD_L - PAD_R
const CHART_H = VIEW_H - PAD_T - PAD_B

type MetricKey = 'cpu' | 'ram' | 'disk' | 'load1'

const metricColors: Record<MetricKey, string> = {
    cpu: '#3b82f6',
    ram: '#a855f7',
    disk: '#f59e0b',
    load1: '#6ee7b7',
}

function buildChartData(points: HistoryPoint[], key: MetricKey): { x: number; y: number }[] {
    const valid = points.filter((p) => key !== 'load1' || p.load1 !== null)
    if (valid.length === 0) return []

    const timestamps = valid.map((p) => new Date(p.t).getTime())
    const values = valid.map((p) => (key === 'load1' ? (p.load1 as number) : p[key as 'cpu' | 'ram' | 'disk']))
    const minTs = Math.min(...timestamps)
    const maxTs = Math.max(...timestamps)
    const maxVal = Math.max(...values, 0.01)

    return valid.map((p, i) => {
        const ts = timestamps[i]
        const val = values[i]
        const x = maxTs === minTs ? PAD_L + CHART_W / 2 : PAD_L + ((ts - minTs) / (maxTs - minTs)) * CHART_W
        const y = PAD_T + CHART_H - (val / maxVal) * CHART_H
        return { x, y }
    })
}

function smoothPath(pts: { x: number; y: number }[]): string {
    if (pts.length === 0) return ''
    if (pts.length === 1) return `M ${pts[0].x.toFixed(1)},${pts[0].y.toFixed(1)}`
    let d = `M ${pts[0].x.toFixed(1)},${pts[0].y.toFixed(1)}`
    for (let i = 1; i < pts.length; i++) {
        const cp = (pts[i - 1].x + pts[i].x) / 2
        d += ` C ${cp.toFixed(1)},${pts[i - 1].y.toFixed(1)} ${cp.toFixed(1)},${pts[i].y.toFixed(1)} ${pts[i].x.toFixed(1)},${pts[i].y.toFixed(1)}`
    }
    return d
}

function areaPath(pts: { x: number; y: number }[]): string {
    if (pts.length === 0) return ''
    const bottom = PAD_T + CHART_H
    const curve = smoothPath(pts)
    return `${curve} L ${pts[pts.length - 1].x.toFixed(1)},${bottom} L ${pts[0].x.toFixed(1)},${bottom} Z`
}

function thresholdY(percent: number, maxVal: number): number {
    return PAD_T + CHART_H - (percent / maxVal) * CHART_H
}

// x-axis labels for chart
const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

function xLabels(points: HistoryPoint[]): { x: number; label: string }[] {
    if (points.length === 0) return []
    const timestamps = points.map((p) => new Date(p.t).getTime())
    const minTs = Math.min(...timestamps)
    const maxTs = Math.max(...timestamps)
    const count = Math.min(6, points.length)
    const result: { x: number; label: string }[] = []
    for (let i = 0; i < count; i++) {
        const ratio = count > 1 ? i / (count - 1) : 0.5
        const ts = minTs + (maxTs - minTs) * ratio
        const d = new Date(ts)
        const label = activePeriod.value === '24h'
            ? `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
            : `${months[d.getMonth()]} ${d.getDate()}`
        result.push({ x: PAD_L + CHART_W * ratio, label })
    }
    return result
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function mbToGb(mb: number): string {
    return (mb / 1024).toFixed(1)
}

function relativeTime(iso: string | null): string {
    if (!iso) return ''
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60) return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    return `${Math.floor(diff / 3600)}h ago`
}

// Determine if a threshold value is an override vs global default
const hasThresholdOverrides = computed(() => {
    // The thresholds() method on the model already merges — we just display
    // what's in server.thresholds (merged). The overrides are in settings.thresholds.
    return false // display-only; the backend returns resolved values
})
</script>

<template>
    <Head :title="server.name" />

    <div class="space-y-8">
        <!-- Header -->
        <PageHeader :title="server.name" description="Server health & metrics overview">
            <template #actions>
                <Link
                    :href="route('servers.index')"
                    class="flex items-center gap-1.5 text-sm text-zinc-500 hover:text-white transition-colors px-3 py-2 rounded-lg hover:bg-white/5"
                >
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 18 9 12 15 6" />
                    </svg>
                    Servers
                </Link>
                <Link :href="route('servers.edit', server.id)">
                    <button class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-zinc-400 hover:text-white hover:bg-white/5 border border-white/5 transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                        </svg>
                        Edit
                    </button>
                </Link>
                <button
                    @click="showDeleteDialog = true"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-red-400 hover:text-red-300 hover:bg-red-500/10 border border-red-500/20 transition-colors"
                >
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6" />
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                        <path d="M10 11v6" /><path d="M14 11v6" />
                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                    </svg>
                    Delete
                </button>
            </template>
        </PageHeader>

        <!-- Copilot contextual insights (hidden when empty) -->
        <ContextualInsights
            :items="props.insights ?? []"
            :title="`Copilot — ${server.name}`"
            storage-key="ctx-insights.servers"
            @changed="router.reload({ only: ['insights'] })"
        />

        <!-- Status row -->
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2 px-3 py-2 rounded-lg border border-white/5 bg-white/[0.02]">
                <span
                    v-if="server.is_active"
                    class="block w-2 h-2 rounded-full bg-emerald-500"
                />
                <span v-else class="block w-2 h-2 rounded-full border-2 border-zinc-600 bg-transparent" />
                <span class="text-sm font-medium" :class="server.is_active ? 'text-zinc-300' : 'text-zinc-500'">
                    {{ server.is_active ? 'Active' : 'Inactive' }}
                </span>
            </div>

            <div class="flex items-center gap-2 px-3 py-2 rounded-lg border border-white/5 bg-white/[0.02]">
                <span
                    :class="['block w-2 h-2 rounded-full', server.has_monitoring ? 'bg-emerald-500' : 'bg-zinc-700']"
                />
                <span class="text-sm font-medium" :class="server.has_monitoring ? 'text-zinc-300' : 'text-zinc-500'">
                    {{ server.has_monitoring ? 'Agent connected' : 'No agent' }}
                </span>
            </div>

            <div v-if="latest?.captured_at" class="flex items-center gap-2 px-3 py-2 rounded-lg border border-white/5 bg-white/[0.02]">
                <svg class="w-3.5 h-3.5 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
                </svg>
                <span class="text-sm text-zinc-500 font-mono">{{ relativeTime(latest.captured_at) }}</span>
            </div>

            <div v-if="server.dokploy_server_id" class="flex items-center gap-2 px-3 py-2 rounded-lg border border-white/5 bg-white/[0.02]">
                <span class="text-[10px] text-zinc-600 font-mono">Dokploy:</span>
                <span class="text-sm font-mono text-zinc-500">{{ server.dokploy_server_id }}</span>
            </div>
        </div>

        <!-- Current metrics gauges -->
        <div v-if="latest" class="p-5 border border-white/5 rounded-xl bg-white/[0.02] space-y-5">
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Current metrics</h2>
                <span class="text-[10px] text-zinc-700 font-mono">{{ relativeTime(latest.captured_at) }}</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                <MetricGauge
                    label="CPU"
                    :percent="latest.cpu"
                    :warning="server.thresholds.cpu_warning"
                    :critical="server.thresholds.cpu_critical"
                />
                <MetricGauge
                    label="RAM"
                    :percent="latest.ram"
                    :warning="server.thresholds.ram_warning"
                    :critical="server.thresholds.ram_critical"
                    :detail="`${mbToGb(latest.ram_used_mb)}/${mbToGb(latest.ram_total_mb)} GB`"
                />
                <MetricGauge
                    label="Disk"
                    :percent="latest.disk"
                    :warning="server.thresholds.disk_warning"
                    :critical="server.thresholds.disk_critical"
                    :detail="`${latest.disk_used_gb}/${latest.disk_total_gb} GB`"
                />
            </div>

            <!-- Load average -->
            <div v-if="latest.load_avg_1 !== null" class="flex items-center justify-between pt-1 text-xs border-t border-white/5">
                <span class="text-zinc-500 uppercase tracking-wider text-[10px] font-semibold">Load avg (1/5/15 min)</span>
                <span class="font-mono text-zinc-300">
                    {{ latest.load_avg_1?.toFixed(2) }}
                    <span class="text-zinc-600">·</span>
                    {{ latest.load_avg_5?.toFixed(2) }}
                    <span class="text-zinc-600">·</span>
                    {{ latest.load_avg_15?.toFixed(2) }}
                </span>
            </div>
        </div>

        <!-- No metrics yet -->
        <div
            v-else-if="server.has_monitoring"
            class="p-8 border border-white/5 rounded-xl bg-white/[0.02] text-center text-zinc-500"
        >
            Waiting for first metrics…
        </div>
        <!-- No agent configured: without this branch a fresh server showed no
             metrics section at all, with nothing explaining why. -->
        <div
            v-else
            class="p-8 border border-white/5 rounded-xl bg-white/[0.02] text-center"
        >
            <p class="text-zinc-400 font-medium">No monitoring agent configured</p>
            <p class="text-zinc-500 text-sm mt-1">
                Generate an ingest token below and install the agent on this server to start collecting CPU, RAM and disk metrics.
            </p>
        </div>


        <!-- History charts.
             The wrapper is NOT gated on history.length: the period is resolved
             server-side now, so an empty window (a server that stopped
             reporting more than 24 h ago, say) would otherwise hide the period
             selector itself and leave no way to widen the range. -->
        <div v-if="server.has_monitoring || history.length > 0" class="space-y-4">
            <!-- Period selector -->
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-500">History</h2>
                <div class="flex gap-1">
                    <button
                        v-for="p in periods"
                        :key="p.value"
                        @click="switchPeriod(p.value)"
                        :class="[
                            'px-3 py-1.5 text-xs font-medium rounded border transition-colors',
                            activePeriod === p.value
                                ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'
                                : 'bg-white/5 text-zinc-400 border-white/10 hover:bg-white/10 hover:text-white',
                        ]"
                    >
                        {{ p.label }}
                    </button>
                </div>
            </div>

            <!-- Empty window: the selector above stays reachable so the user
                 can widen the range instead of facing a blank section. -->
            <div
                v-if="history.length === 0"
                class="p-8 border border-white/5 rounded-xl bg-white/[0.02] text-center text-zinc-500"
            >
                No metrics in the last {{ activePeriod === '24h' ? '24 hours' : activePeriod === '7d' ? '7 days' : '30 days' }}.
                <span v-if="activePeriod !== '30d'">Try a wider period.</span>
            </div>

            <!-- CPU chart -->
            <div v-if="history.length > 0" class="p-5 border border-white/5 rounded-xl bg-white/[0.02]">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold text-blue-400 uppercase tracking-wider">CPU %</span>
                    <div class="flex items-center gap-3">
                        <span class="flex items-center gap-1 text-[10px] text-amber-400/70">
                            <span class="inline-block w-4 h-px border-t border-dashed border-amber-400/50"></span>
                            warn {{ server.thresholds.cpu_warning }}%
                        </span>
                        <span class="flex items-center gap-1 text-[10px] text-red-400/70">
                            <span class="inline-block w-4 h-px border-t border-dashed border-red-400/50"></span>
                            crit {{ server.thresholds.cpu_critical }}%
                        </span>
                    </div>
                </div>
                <template v-if="filteredHistory.length > 0">
                    <svg :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" class="w-full h-auto">
                        <defs>
                            <linearGradient id="cpu-grad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.25" />
                                <stop offset="100%" stop-color="#3b82f6" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <!-- Grid lines -->
                        <line v-for="v in [25, 50, 75, 100]" :key="`cpu-g-${v}`"
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H - (v / 100) * CHART_H"
                            :y2="PAD_T + CHART_H - (v / 100) * CHART_H"
                            stroke="white" stroke-opacity="0.05" stroke-dasharray="3 3"
                        />
                        <!-- Y labels -->
                        <text v-for="v in [0, 50, 100]" :key="`cpu-y-${v}`"
                            :x="PAD_L - 5" :y="PAD_T + CHART_H - (v / 100) * CHART_H + 4"
                            text-anchor="end" style="font-size: 10px; fill: #52525b"
                        >{{ v }}%</text>
                        <!-- Threshold lines (warn / critical) - only within chart range -->
                        <line
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H - (server.thresholds.cpu_warning / 100) * CHART_H"
                            :y2="PAD_T + CHART_H - (server.thresholds.cpu_warning / 100) * CHART_H"
                            stroke="#f59e0b" stroke-opacity="0.4" stroke-width="1" stroke-dasharray="4 3"
                        />
                        <line
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H - (server.thresholds.cpu_critical / 100) * CHART_H"
                            :y2="PAD_T + CHART_H - (server.thresholds.cpu_critical / 100) * CHART_H"
                            stroke="#ef4444" stroke-opacity="0.4" stroke-width="1" stroke-dasharray="4 3"
                        />
                        <!-- Area + line -->
                        <path :d="areaPath(buildChartData(filteredHistory, 'cpu'))" fill="url(#cpu-grad)" />
                        <path :d="smoothPath(buildChartData(filteredHistory, 'cpu'))" stroke="#3b82f6" stroke-width="1.5" fill="none" stroke-linecap="round" />
                        <!-- X labels -->
                        <text v-for="(lbl, i) in xLabels(filteredHistory)" :key="`cpu-x-${i}`"
                            :x="lbl.x" :y="VIEW_H - 6"
                            text-anchor="middle" style="font-size: 10px; fill: #52525b"
                        >{{ lbl.label }}</text>
                    </svg>
                </template>
                <div v-else class="flex items-center justify-center h-20 text-xs text-zinc-700">No data for this period</div>
            </div>

            <!-- RAM chart -->
            <div v-if="history.length > 0" class="p-5 border border-white/5 rounded-xl bg-white/[0.02]">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold text-purple-400 uppercase tracking-wider">RAM %</span>
                    <div class="flex items-center gap-3">
                        <span class="flex items-center gap-1 text-[10px] text-amber-400/70">
                            <span class="inline-block w-4 h-px border-t border-dashed border-amber-400/50"></span>
                            warn {{ server.thresholds.ram_warning }}%
                        </span>
                        <span class="flex items-center gap-1 text-[10px] text-red-400/70">
                            <span class="inline-block w-4 h-px border-t border-dashed border-red-400/50"></span>
                            crit {{ server.thresholds.ram_critical }}%
                        </span>
                    </div>
                </div>
                <template v-if="filteredHistory.length > 0">
                    <svg :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" class="w-full h-auto">
                        <defs>
                            <linearGradient id="ram-grad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#a855f7" stop-opacity="0.25" />
                                <stop offset="100%" stop-color="#a855f7" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <line v-for="v in [25, 50, 75, 100]" :key="`ram-g-${v}`"
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H - (v / 100) * CHART_H"
                            :y2="PAD_T + CHART_H - (v / 100) * CHART_H"
                            stroke="white" stroke-opacity="0.05" stroke-dasharray="3 3"
                        />
                        <text v-for="v in [0, 50, 100]" :key="`ram-y-${v}`"
                            :x="PAD_L - 5" :y="PAD_T + CHART_H - (v / 100) * CHART_H + 4"
                            text-anchor="end" style="font-size: 10px; fill: #52525b"
                        >{{ v }}%</text>
                        <line
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H - (server.thresholds.ram_warning / 100) * CHART_H"
                            :y2="PAD_T + CHART_H - (server.thresholds.ram_warning / 100) * CHART_H"
                            stroke="#f59e0b" stroke-opacity="0.4" stroke-width="1" stroke-dasharray="4 3"
                        />
                        <line
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H - (server.thresholds.ram_critical / 100) * CHART_H"
                            :y2="PAD_T + CHART_H - (server.thresholds.ram_critical / 100) * CHART_H"
                            stroke="#ef4444" stroke-opacity="0.4" stroke-width="1" stroke-dasharray="4 3"
                        />
                        <path :d="areaPath(buildChartData(filteredHistory, 'ram'))" fill="url(#ram-grad)" />
                        <path :d="smoothPath(buildChartData(filteredHistory, 'ram'))" stroke="#a855f7" stroke-width="1.5" fill="none" stroke-linecap="round" />
                        <text v-for="(lbl, i) in xLabels(filteredHistory)" :key="`ram-x-${i}`"
                            :x="lbl.x" :y="VIEW_H - 6"
                            text-anchor="middle" style="font-size: 10px; fill: #52525b"
                        >{{ lbl.label }}</text>
                    </svg>
                </template>
                <div v-else class="flex items-center justify-center h-20 text-xs text-zinc-700">No data for this period</div>
            </div>

            <!-- Disk chart -->
            <div v-if="history.length > 0" class="p-5 border border-white/5 rounded-xl bg-white/[0.02]">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold text-amber-400 uppercase tracking-wider">Disk %</span>
                    <div class="flex items-center gap-3">
                        <span class="flex items-center gap-1 text-[10px] text-amber-400/70">
                            <span class="inline-block w-4 h-px border-t border-dashed border-amber-400/50"></span>
                            warn {{ server.thresholds.disk_warning }}%
                        </span>
                        <span class="flex items-center gap-1 text-[10px] text-red-400/70">
                            <span class="inline-block w-4 h-px border-t border-dashed border-red-400/50"></span>
                            crit {{ server.thresholds.disk_critical }}%
                        </span>
                    </div>
                </div>
                <template v-if="filteredHistory.length > 0">
                    <svg :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" class="w-full h-auto">
                        <defs>
                            <linearGradient id="disk-grad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#f59e0b" stop-opacity="0.2" />
                                <stop offset="100%" stop-color="#f59e0b" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <line v-for="v in [25, 50, 75, 100]" :key="`disk-g-${v}`"
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H - (v / 100) * CHART_H"
                            :y2="PAD_T + CHART_H - (v / 100) * CHART_H"
                            stroke="white" stroke-opacity="0.05" stroke-dasharray="3 3"
                        />
                        <text v-for="v in [0, 50, 100]" :key="`disk-y-${v}`"
                            :x="PAD_L - 5" :y="PAD_T + CHART_H - (v / 100) * CHART_H + 4"
                            text-anchor="end" style="font-size: 10px; fill: #52525b"
                        >{{ v }}%</text>
                        <line
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H - (server.thresholds.disk_warning / 100) * CHART_H"
                            :y2="PAD_T + CHART_H - (server.thresholds.disk_warning / 100) * CHART_H"
                            stroke="#f59e0b" stroke-opacity="0.4" stroke-width="1" stroke-dasharray="4 3"
                        />
                        <line
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H - (server.thresholds.disk_critical / 100) * CHART_H"
                            :y2="PAD_T + CHART_H - (server.thresholds.disk_critical / 100) * CHART_H"
                            stroke="#ef4444" stroke-opacity="0.4" stroke-width="1" stroke-dasharray="4 3"
                        />
                        <path :d="areaPath(buildChartData(filteredHistory, 'disk'))" fill="url(#disk-grad)" />
                        <path :d="smoothPath(buildChartData(filteredHistory, 'disk'))" stroke="#f59e0b" stroke-width="1.5" fill="none" stroke-linecap="round" />
                        <text v-for="(lbl, i) in xLabels(filteredHistory)" :key="`disk-x-${i}`"
                            :x="lbl.x" :y="VIEW_H - 6"
                            text-anchor="middle" style="font-size: 10px; fill: #52525b"
                        >{{ lbl.label }}</text>
                    </svg>
                </template>
                <div v-else class="flex items-center justify-center h-20 text-xs text-zinc-700">No data for this period</div>
            </div>

            <!-- Load avg chart (if any load1 data exists) -->
            <div v-if="history.some(p => p.load1 !== null)" class="p-5 border border-white/5 rounded-xl bg-white/[0.02]">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold text-emerald-300 uppercase tracking-wider">Load avg (1 min)</span>
                </div>
                <template v-if="filteredHistory.some(p => p.load1 !== null)">
                    <svg :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" class="w-full h-auto">
                        <defs>
                            <linearGradient id="load-grad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#6ee7b7" stop-opacity="0.2" />
                                <stop offset="100%" stop-color="#6ee7b7" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <line v-for="v in [1, 2, 3, 4]" :key="`load-g-${v}`"
                            :x1="PAD_L" :x2="VIEW_W - PAD_R"
                            :y1="PAD_T + CHART_H / 4 * (4 - v)"
                            :y2="PAD_T + CHART_H / 4 * (4 - v)"
                            stroke="white" stroke-opacity="0.05" stroke-dasharray="3 3"
                        />
                        <path :d="areaPath(buildChartData(filteredHistory, 'load1'))" fill="url(#load-grad)" />
                        <path :d="smoothPath(buildChartData(filteredHistory, 'load1'))" stroke="#6ee7b7" stroke-width="1.5" fill="none" stroke-linecap="round" />
                        <text v-for="(lbl, i) in xLabels(filteredHistory)" :key="`load-x-${i}`"
                            :x="lbl.x" :y="VIEW_H - 6"
                            text-anchor="middle" style="font-size: 10px; fill: #52525b"
                        >{{ lbl.label }}</text>
                    </svg>
                </template>
                <div v-else class="flex items-center justify-center h-20 text-xs text-zinc-700">No data for this period</div>
            </div>
        </div>

        <!-- Agent token section -->
        <div class="p-5 border border-white/5 rounded-xl bg-white/[0.02] space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Metrics agent token</h2>
                <span
                    v-if="server.has_ingest_token"
                    class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border bg-emerald-500/10 text-emerald-400 border-emerald-500/20"
                >
                    Configured
                </span>
                <span
                    v-else
                    class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border bg-amber-500/10 text-amber-400 border-amber-500/20"
                >
                    Not configured
                </span>
            </div>

            <!-- One-time token display after generate/rotate -->
            <div
                v-if="serverToken"
                class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4 space-y-3"
            >
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    <span class="text-sm font-semibold text-emerald-400">Copy this token — it will not be shown again</span>
                </div>

                <div class="flex items-center gap-2">
                    <code class="flex-1 text-sm font-mono text-emerald-300 bg-black/30 border border-emerald-500/20 px-3 py-2 rounded-lg overflow-x-auto whitespace-nowrap">
                        {{ tokenVisible ? serverToken : maskToken(serverToken) }}
                    </code>
                    <button
                        @click="tokenVisible = !tokenVisible"
                        class="shrink-0 px-2.5 py-2 text-xs text-zinc-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-md transition-colors"
                    >
                        {{ tokenVisible ? 'Hide' : 'Show' }}
                    </button>
                    <button
                        @click="copyToken"
                        :class="['shrink-0 px-2.5 py-2 text-xs rounded-md border transition-colors', tokenCopied ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-zinc-400 hover:text-white bg-white/5 hover:bg-white/10 border-white/10']"
                    >
                        {{ tokenCopied ? 'Copied!' : 'Copy' }}
                    </button>
                </div>

                <!-- Installation snippet -->
                <div class="mt-1 space-y-2">
                    <p class="text-xs text-zinc-500">Install snippet — run on the monitored server:</p>
                    <pre class="text-[11px] font-mono text-zinc-400 bg-black/30 border border-white/5 rounded-lg p-3 overflow-x-auto whitespace-pre-wrap break-all">sudo bash /path/to/infra/server-agent/install.sh
# Then edit the config:
sudo editor /etc/up-server-agent.env
# Set:
UP_METRICS_URL=https://&lt;your-up-host&gt;/api/servers/metrics
UP_SERVER_TOKEN={{ serverToken }}</pre>
                </div>
            </div>

            <!-- Existing token actions (token already set, no flash) -->
            <div v-else class="flex flex-wrap items-center gap-3">
                <button
                    v-if="server.has_ingest_token"
                    @click="rotateToken"
                    :disabled="rotatingToken"
                    class="flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-amber-500/20 text-amber-400 hover:text-amber-300 hover:bg-amber-500/10 transition-colors disabled:opacity-60"
                >
                    <svg v-if="rotatingToken" class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                    </svg>
                    <svg v-else class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="1 4 1 10 7 10" /><path d="M3.51 15a9 9 0 1 0 .49-4" />
                    </svg>
                    Regenerate token
                </button>
                <button
                    v-else
                    @click="rotateToken"
                    :disabled="rotatingToken"
                    class="btn-primary flex items-center gap-2 px-4 py-2 disabled:opacity-60"
                >
                    <svg v-if="rotatingToken" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                    </svg>
                    Generate token
                </button>
                <p class="text-xs text-zinc-600">
                    {{ server.has_ingest_token ? 'Generate a new token to replace the current one.' : 'Generate a bearer token for the metrics agent.' }}
                </p>
            </div>
        </div>

        <!-- Thresholds (effective) -->
        <div class="p-5 border border-white/5 rounded-xl bg-white/[0.02]">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 mb-4">Effective alert thresholds</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div v-for="(item) in [
                    { label: 'CPU warning', value: server.thresholds.cpu_warning, color: 'amber' },
                    { label: 'CPU critical', value: server.thresholds.cpu_critical, color: 'red' },
                    { label: 'RAM warning', value: server.thresholds.ram_warning, color: 'amber' },
                    { label: 'RAM critical', value: server.thresholds.ram_critical, color: 'red' },
                    { label: 'Disk warning', value: server.thresholds.disk_warning, color: 'amber' },
                    { label: 'Disk critical', value: server.thresholds.disk_critical, color: 'red' },
                ]" :key="item.label"
                    class="flex items-center justify-between py-2 px-3 rounded-lg bg-white/[0.02] border border-white/5"
                >
                    <span class="text-xs text-zinc-500">{{ item.label }}</span>
                    <span :class="['text-sm font-mono font-semibold', item.color === 'amber' ? 'text-amber-400' : 'text-red-400']">
                        {{ item.value }}%
                    </span>
                </div>
                <div class="flex items-center justify-between py-2 px-3 rounded-lg bg-white/[0.02] border border-white/5">
                    <span class="text-xs text-zinc-500">CPU sustained pts</span>
                    <span class="text-sm font-mono font-semibold text-zinc-300">{{ server.thresholds.cpu_sustained_points }}</span>
                </div>
                <div class="flex items-center justify-between py-2 px-3 rounded-lg bg-white/[0.02] border border-white/5">
                    <span class="text-xs text-zinc-500">Load warning ratio</span>
                    <span class="text-sm font-mono font-semibold text-amber-400">
                        {{ server.thresholds.load_warning }}
                        <span v-if="server.thresholds.load_cores" class="text-zinc-600 text-xs">({{ server.thresholds.load_cores }} cores)</span>
                    </span>
                </div>
                <div class="flex items-center justify-between py-2 px-3 rounded-lg bg-white/[0.02] border border-white/5">
                    <span class="text-xs text-zinc-500">Load critical ratio</span>
                    <span :class="['text-sm font-mono font-semibold', server.thresholds.load_cores ? 'text-red-400' : 'text-zinc-600']">
                        {{ server.thresholds.load_cores ? server.thresholds.load_critical : '—' }}
                    </span>
                </div>
            </div>
            <p class="mt-3 text-[11px] text-zinc-700">
                These are the resolved values (global defaults merged with any per-server overrides).
                <Link :href="route('servers.edit', server.id)" class="text-zinc-500 hover:text-white underline ml-1">Edit overrides</Link>
            </p>
        </div>

        <!-- Hosted sites -->
        <div v-if="sites.length > 0">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 mb-3">Hosted sites</h2>
            <div class="flex flex-col gap-2">
                <Link
                    v-for="site in sites"
                    :key="site.id"
                    :href="route('sites.show', site.id)"
                    class="group flex items-center justify-between py-3 px-4 border border-white/5 rounded-xl bg-white/[0.02] hover:bg-white/[0.04] transition-colors"
                >
                    <span class="text-sm font-mono text-zinc-300 group-hover:text-white transition-colors">{{ site.primary_domain }}</span>
                    <svg class="w-4 h-4 text-zinc-600 group-hover:text-zinc-400 transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6" />
                    </svg>
                </Link>
            </div>
        </div>
    </div>

    <!-- Delete confirmation -->
    <ConfirmDialog
        v-model:show="showDeleteDialog"
        title="Delete Server"
        :message="`Are you sure you want to delete '${server.name}'? All metrics history will be deleted.`"
        confirm-label="Delete Server"
        variant="danger"
        @confirm="handleDelete"
    />
</template>
