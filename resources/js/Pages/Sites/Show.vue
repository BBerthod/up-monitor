<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import BackLink from '@/Components/BackLink.vue'
import GlassCard from '@/Components/GlassCard.vue'
import MetricGauge from '@/Components/MetricGauge.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import ContextualInsights from '@/Components/Triage/ContextualInsights.vue'
import type { TriageItemData } from '@/Components/Triage/ContextualInsights.vue'

// ─── Types ────────────────────────────────────────────────────────────────────

interface MonitorRef {
    id: number
    name: string
    type: string
    status: 'up' | 'down' | 'paused'
    uptime_30d: number | null
    response_ms: number | null
}

interface WarmingData {
    hit_ratio: number | null
    last_run_at: string | null
}

interface Availability {
    uptime_30d: number | null
    incidents_30d: number | null
    incidents_open: number | null
    mttr_minutes: number | null
    monitors: MonitorRef[]
    warming: WarmingData | null
}

interface SeoData {
    gsc: { clicks_28d: number; impressions_28d: number } | null
    bing: { clicks_28d: number } | null
    ga4: { users_28d: number } | null
    position_avg: number | null
    ttfb_p75: number | null
    lighthouse: {
        performance: number | null
        seo: number | null
        accessibility: number | null
        best_practices: number | null
        scored_at: string
    } | null
}

interface InfrastructureData {
    server_metric: {
        cpu: number
        ram: number
        disk: number
        load_1: number | null
        captured_at: string | null
    } | null
    cohosted_sites: { id: number; name: string }[]
}

interface Cockpit {
    availability: Availability
    seo: SeoData
    infrastructure: InfrastructureData
}

interface HealthData {
    score: number
    trend?: 'up' | 'down' | 'stable' | null
}

interface ServerRef {
    id: number
    name: string
}

interface Site {
    id: number
    name: string
    domain: string
    locales: string[] | null
    server: ServerRef | null
    is_up: boolean | null
    health: HealthData | null
}

interface TimelineItem {
    kind: 'incident' | 'insight'
    at: string
    title: string
    severity?: string | null
    resolved_at?: string | null
    url?: string | null
}

const props = defineProps<{
    site: Site
    cockpit: Cockpit | null
    insights: TriageItemData[]
    timeline: TimelineItem[]
}>()

// ─── Realtime ─────────────────────────────────────────────────────────────────
useRealtimeUpdates({
    onMonitorChecked: ['site', 'cockpit', 'insights', 'timeline'],
    onServerMetricReceived: ['site', 'cockpit', 'insights', 'timeline'],
    onInsightChanged: ['site', 'cockpit', 'insights', 'timeline'],
    onIncidentCreated: ['site', 'cockpit', 'insights', 'timeline'],
    onIncidentResolved: ['site', 'cockpit', 'insights', 'timeline'],
})

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmt(n: number | null | undefined, suffix = ''): string {
    if (n == null) return '—'
    return n.toLocaleString() + suffix
}

function fmtPct(n: number | null | undefined): string {
    if (n == null) return '—'
    return n.toFixed(1) + '%'
}

function fmtMs(n: number | null | undefined): string {
    if (n == null) return '—'
    return Math.round(n) + ' ms'
}

function timeAgo(iso: string | null | undefined): string {
    if (!iso) return ''
    const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60_000)
    if (m < 1) return 'just now'
    if (m < 60) return `${m}m ago`
    if (m < 1440) return `${Math.floor(m / 60)}h ago`
    return `${Math.floor(m / 1440)}d ago`
}

function formatDate(iso: string | null | undefined): string {
    if (!iso) return ''
    return new Date(iso).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// ─── Derived ──────────────────────────────────────────────────────────────────

const siteStatus = computed<'up' | 'down' | 'paused'>(() => {
    if (props.site.is_up === null) return 'paused'
    return props.site.is_up ? 'up' : 'down'
})

const healthScore = computed(() => props.site.health?.score ?? null)

const healthBarWidth = computed(() => {
    if (healthScore.value == null) return '0%'
    return `${Math.max(0, Math.min(100, healthScore.value))}%`
})

const healthBarColor = computed(() => {
    const s = healthScore.value
    if (s == null) return 'bg-zinc-600'
    if (s >= 80) return 'bg-emerald-500'
    if (s >= 50) return 'bg-amber-400'
    return 'bg-red-500'
})

const healthScoreColor = computed(() => {
    const s = healthScore.value
    if (s == null) return 'text-zinc-500'
    if (s >= 80) return 'text-emerald-400'
    if (s >= 50) return 'text-amber-400'
    return 'text-red-400'
})

const trendIcon = computed(() => {
    const t = props.site.health?.trend
    if (t === 'up') return '▲'
    if (t === 'down') return '▼'
    return null
})

const trendColor = computed(() => {
    const t = props.site.health?.trend
    if (t === 'up') return 'text-emerald-400'
    if (t === 'down') return 'text-red-400'
    return 'text-zinc-500'
})

const availability = computed(() => props.cockpit?.availability ?? null)
const seo = computed(() => props.cockpit?.seo ?? null)
const infra = computed(() => props.cockpit?.infrastructure ?? null)

const monitorsUp = computed(() => availability.value?.monitors.filter(m => m.status === 'up').length ?? 0)
const monitorsTotal = computed(() => availability.value?.monitors.length ?? 0)

const ttfbColor = computed(() => {
    const ms = seo.value?.ttfb_p75
    if (ms == null) return 'text-zinc-400'
    if (ms < 800) return 'text-emerald-400'
    if (ms < 2000) return 'text-amber-400'
    return 'text-red-400'
})

const monitorTypeColors: Record<string, string> = {
    http: 'bg-blue-500/15 text-blue-400 border-blue-500/20',
    ping: 'bg-violet-500/15 text-violet-400 border-violet-500/20',
    port: 'bg-amber-500/15 text-amber-400 border-amber-500/20',
    dns:  'bg-cyan-500/15 text-cyan-400 border-cyan-500/20',
}

// Timeline sorted newest first (defensive: sort if backend sends unsorted)
const sortedTimeline = computed(() =>
    [...props.timeline].sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime()),
)

const insightStorageKey = computed(() => `ctx-insights.sites.${props.site.id}`)

// Lighthouse score colour — same 90/50 thresholds as LighthouseScores.vue
function lhColor(v: number | null | undefined): string {
    if (v == null) return '#64748b'
    if (v >= 90) return '#0cce6b'
    if (v >= 50) return '#ffa400'
    return '#ff4e42'
}

// Flat array of 4 scores for the inline mini-grid
const lhScores = computed(() => {
    const lh = seo.value?.lighthouse
    if (!lh) return null
    return [
        { label: 'Perf', value: lh.performance },
        { label: 'SEO',  value: lh.seo },
        { label: 'A11y', value: lh.accessibility },
        { label: 'BP',   value: lh.best_practices },
    ]
})

const siteUrl = computed(() => `https://${props.site.domain}`)
</script>

<template>
    <Head :title="site.name" />

    <div class="space-y-6">

        <!-- ─── Header ──────────────────────────────────────────────────────── -->
        <div class="flex flex-col gap-4">
            <!-- Breadcrumb -->
            <BackLink :href="route('sites.index')" label="Sites" />

            <div class="flex flex-col sm:flex-row sm:items-start gap-4">
                <!-- Left: identity + health bar -->
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold text-white tracking-tight truncate">
                            {{ site.name }}
                        </h1>
                        <StatusBadge :status="siteStatus" size="md" />
                    </div>

                    <!-- Sub-line: domain + server + locales + monitor count -->
                    <div class="flex flex-wrap items-center gap-2 mt-1.5 text-sm text-zinc-500">
                        <a
                            :href="siteUrl"
                            target="_blank"
                            rel="noopener"
                            class="font-mono text-zinc-400 hover:text-white transition-colors"
                        >{{ site.domain }}</a>

                        <template v-if="site.server">
                            <span class="text-zinc-700" aria-hidden="true">·</span>
                            <Link
                                :href="route('servers.show', site.server.id)"
                                class="hover:text-zinc-300 transition-colors"
                            >{{ site.server.name }}</Link>
                        </template>

                        <template v-if="site.locales && site.locales.length">
                            <span class="text-zinc-700" aria-hidden="true">·</span>
                            <span>{{ site.locales.join(' / ') }}</span>
                        </template>

                        <template v-if="monitorsTotal > 0">
                            <span class="text-zinc-700" aria-hidden="true">·</span>
                            <span>{{ monitorsTotal }} monitor{{ monitorsTotal === 1 ? '' : 's' }}</span>
                        </template>
                    </div>

                    <!-- Health score bar — visible only when both health object and score are present -->
                    <div v-if="site.health != null && healthScore != null" class="mt-3 flex items-center gap-3">
                        <div class="flex-1 h-1.5 rounded-full bg-white/5 overflow-hidden max-w-[220px]">
                            <div
                                class="h-full rounded-full transition-all duration-700"
                                :class="healthBarColor"
                                :style="{ width: healthBarWidth }"
                                role="progressbar"
                                :aria-valuenow="healthScore"
                                :aria-valuemin="0"
                                :aria-valuemax="100"
                                :aria-label="`Health score: ${healthScore}/100`"
                            />
                        </div>
                        <span :class="['text-sm font-semibold tabular-nums', healthScoreColor]">
                            {{ healthScore }}<span class="text-zinc-600">/100</span>
                        </span>
                        <span v-if="trendIcon" :class="['text-xs font-bold', trendColor]" :aria-label="`Trend: ${site.health?.trend}`">
                            {{ trendIcon }}
                        </span>
                    </div>
                </div>

                <!-- Right: action buttons -->
                <div class="flex items-center gap-2 shrink-0">
                    <a
                        :href="siteUrl"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-sm text-zinc-300 hover:bg-white/10 hover:text-white transition-colors"
                    >
                        Open site
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/>
                            <line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                    </a>
                    <Link
                        :href="route('sites.edit', site.id)"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-sm text-white font-medium transition-colors"
                    >
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        Edit
                    </Link>
                </div>
            </div>
        </div>

        <!-- ─── Copilot insights (collapsible, hidden when empty) ─────────── -->
        <ContextualInsights
            :items="insights"
            :title="`Copilot — ${site.name}`"
            :storage-key="insightStorageKey"
        />

        <!-- ─── Cockpit columns (3-col grid) ──────────────────────────────── -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            <!-- ── AVAILABILITY ──────────────────────────────────────────── -->
            <GlassCard :padding="5" class="flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Availability</h2>
                    <span class="text-[10px] text-zinc-600 uppercase tracking-wide">30d</span>
                </div>

                <div v-if="availability" class="flex-1 space-y-4">
                    <!-- Uptime big number -->
                    <div>
                        <div class="text-4xl font-bold tracking-tight tabular-nums" :class="(availability.uptime_30d ?? 0) >= 99 ? 'text-emerald-400' : (availability.uptime_30d ?? 0) >= 95 ? 'text-amber-400' : 'text-red-400'">
                            {{ availability.uptime_30d != null ? availability.uptime_30d.toFixed(2) + '%' : '—' }}
                        </div>
                        <div class="text-[11px] text-zinc-500 mt-0.5">Uptime (30d)</div>
                    </div>

                    <!-- Stats row -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="p-3 rounded-lg bg-white/[0.02] border border-white/5">
                            <div class="text-xl font-bold tabular-nums" :class="(availability.incidents_open ?? 0) > 0 ? 'text-red-400' : 'text-white'">
                                {{ availability.incidents_open ?? '—' }}
                            </div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">Open incidents</div>
                        </div>
                        <div class="p-3 rounded-lg bg-white/[0.02] border border-white/5">
                            <div class="text-xl font-bold text-white tabular-nums">
                                {{ availability.incidents_30d ?? '—' }}
                            </div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">Incidents (30d)</div>
                        </div>
                        <div class="col-span-2 p-3 rounded-lg bg-white/[0.02] border border-white/5">
                            <div class="text-xl font-bold text-white tabular-nums">
                                {{ availability.mttr_minutes != null ? availability.mttr_minutes + ' min' : '—' }}
                            </div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">Avg. recovery (MTTR)</div>
                        </div>
                    </div>

                    <!-- Monitor list (compact) -->
                    <div v-if="availability.monitors.length > 0" class="space-y-1.5">
                        <div
                            v-for="m in availability.monitors"
                            :key="m.id"
                            class="flex items-center justify-between gap-2"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                <StatusBadge :status="m.status" :show-label="false" />
                                <Link
                                    :href="route('monitors.show', m.id)"
                                    class="text-xs text-zinc-300 hover:text-white truncate transition-colors"
                                >{{ m.name }}</Link>
                                <span
                                    v-if="m.type"
                                    class="shrink-0 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase border"
                                    :class="monitorTypeColors[m.type] ?? monitorTypeColors.http"
                                >{{ m.type }}</span>
                            </div>
                            <span v-if="m.uptime_30d != null" class="text-[11px] font-mono text-zinc-500 shrink-0 tabular-nums">
                                {{ m.uptime_30d.toFixed(1) }}%
                            </span>
                        </div>
                    </div>

                    <!-- Cache warming -->
                    <div v-if="availability.warming" class="pt-2 border-t border-white/5">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] text-zinc-500">Cache hit ratio</span>
                            <span class="text-[11px] font-mono text-emerald-400 tabular-nums">
                                {{ availability.warming.hit_ratio != null ? (availability.warming.hit_ratio * 100).toFixed(0) + '%' : '—' }}
                            </span>
                        </div>
                        <div v-if="availability.warming.last_run_at" class="text-[10px] text-zinc-700 mt-0.5">
                            last run {{ timeAgo(availability.warming.last_run_at) }}
                        </div>
                    </div>
                </div>

                <div v-else class="flex-1 flex items-center justify-center py-8">
                    <span class="text-sm text-zinc-600">No data yet</span>
                </div>

                <!-- Footer link -->
                <div class="mt-4 pt-4 border-t border-white/5">
                    <Link
                        :href="route('monitors.index')"
                        class="text-xs text-emerald-500 hover:text-emerald-400 transition-colors"
                    >
                        All monitors &rarr;
                    </Link>
                </div>
            </GlassCard>

            <!-- ── SEO & BUSINESS ─────────────────────────────────────────── -->
            <GlassCard :padding="5" class="flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400">SEO &amp; Business</h2>
                    <span class="text-[10px] text-zinc-600 uppercase tracking-wide">28d</span>
                </div>

                <div v-if="seo" class="flex-1 space-y-4">
                    <!-- KPI row: GSC + Bing + GA4 -->
                    <div class="grid grid-cols-3 gap-2">
                        <!-- GSC clicks -->
                        <div class="p-2.5 rounded-lg bg-white/[0.02] border border-white/5">
                            <div class="flex items-center gap-1 mb-1.5">
                                <span class="flex gap-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                </span>
                            </div>
                            <div class="text-lg font-bold text-white tabular-nums">{{ fmt(seo.gsc?.clicks_28d) }}</div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">GSC clicks</div>
                        </div>

                        <!-- Bing clicks -->
                        <div class="p-2.5 rounded-lg bg-white/[0.02] border border-white/5">
                            <div class="mb-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 inline-block"></span>
                            </div>
                            <div class="text-lg font-bold text-white tabular-nums">{{ fmt(seo.bing?.clicks_28d) }}</div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">Bing clicks</div>
                        </div>

                        <!-- GA4 users -->
                        <div class="p-2.5 rounded-lg bg-white/[0.02] border border-white/5">
                            <div class="mb-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-orange-400 inline-block"></span>
                            </div>
                            <div class="text-lg font-bold text-white tabular-nums">{{ fmt(seo.ga4?.users_28d) }}</div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">GA4 users</div>
                        </div>
                    </div>

                    <!-- Position + TTFB -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="p-3 rounded-lg bg-white/[0.02] border border-white/5">
                            <div class="text-xl font-bold text-white tabular-nums">
                                {{ seo.position_avg != null ? seo.position_avg.toFixed(1) : '—' }}
                            </div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">Avg. position</div>
                        </div>
                        <div class="p-3 rounded-lg bg-white/[0.02] border border-white/5">
                            <div class="text-xl font-bold tabular-nums" :class="ttfbColor">
                                {{ fmtMs(seo.ttfb_p75) }}
                            </div>
                            <div class="text-[10px] text-zinc-500 mt-0.5">TTFB p75</div>
                        </div>
                    </div>

                    <!-- Lighthouse mini scores — 4 inline score pills, no web vitals needed -->
                    <div class="pt-2 border-t border-white/5">
                        <div class="text-[10px] text-zinc-600 uppercase tracking-wider mb-2">Lighthouse</div>
                        <div v-if="lhScores" class="grid grid-cols-4 gap-1.5">
                            <div
                                v-for="s in lhScores"
                                :key="s.label"
                                class="flex flex-col items-center gap-1 p-2 rounded-lg bg-white/[0.03]"
                            >
                                <span
                                    class="text-base font-bold font-mono tabular-nums leading-none"
                                    :style="{ color: lhColor(s.value) }"
                                >{{ s.value ?? '—' }}</span>
                                <span class="text-[9px] text-zinc-500 uppercase tracking-wide">{{ s.label }}</span>
                            </div>
                        </div>
                        <div v-else class="text-[11px] text-zinc-600">No Lighthouse data yet</div>
                        <div v-if="seo.lighthouse?.scored_at" class="mt-1.5 text-[10px] text-zinc-700">
                            {{ timeAgo(seo.lighthouse.scored_at) }}
                        </div>
                    </div>
                </div>

                <div v-else class="flex-1 flex items-center justify-center py-8">
                    <span class="text-sm text-zinc-600">No data yet</span>
                </div>

                <!-- Footer link -->
                <div class="mt-4 pt-4 border-t border-white/5">
                    <!-- Scoped KPI & Trends page: the previous target was a
                         "#kpi" anchor that exists nowhere, so the link
                         reloaded the page and scrolled nowhere. -->
                    <Link
                        :href="route('kpi-trends.index', { site: site.id })"
                        class="text-xs text-emerald-500 hover:text-emerald-400 transition-colors"
                    >
                        KPI details &rarr;
                    </Link>
                </div>
            </GlassCard>

            <!-- ── INFRASTRUCTURE ──────────────────────────────────────────── -->
            <GlassCard :padding="5" class="flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Infrastructure</h2>
                    <span v-if="site.server" class="text-[10px] text-zinc-500">{{ site.server.name }}</span>
                </div>

                <div v-if="infra" class="flex-1 space-y-4">
                    <!-- Metric gauges: CPU / RAM / Disk -->
                    <div v-if="infra.server_metric" class="space-y-3">
                        <MetricGauge
                            label="CPU"
                            :percent="infra.server_metric.cpu"
                            :warning="70"
                            :critical="90"
                        />
                        <MetricGauge
                            label="RAM"
                            :percent="infra.server_metric.ram"
                            :warning="80"
                            :critical="95"
                        />
                        <MetricGauge
                            label="Disk"
                            :percent="infra.server_metric.disk"
                            :warning="75"
                            :critical="90"
                        />

                        <!-- Load avg -->
                        <div v-if="infra.server_metric.load_1 != null" class="flex items-center justify-between text-xs pt-1">
                            <span class="text-zinc-500">Load avg (1m)</span>
                            <span class="font-mono text-zinc-300 tabular-nums">{{ infra.server_metric.load_1.toFixed(2) }}</span>
                        </div>

                        <!-- Captured at -->
                        <div v-if="infra.server_metric.captured_at" class="text-[10px] text-zinc-700">
                            Updated {{ timeAgo(infra.server_metric.captured_at) }}
                        </div>
                    </div>

                    <div v-else class="py-4 text-center text-sm text-zinc-600">
                        No metrics received yet
                    </div>

                    <!-- Co-hosted sites -->
                    <div v-if="infra.cohosted_sites.length > 0" class="pt-2 border-t border-white/5">
                        <div class="text-[10px] text-zinc-600 uppercase tracking-wider mb-2">Also on this server</div>
                        <div class="flex flex-wrap gap-1.5">
                            <Link
                                v-for="s in infra.cohosted_sites"
                                :key="s.id"
                                :href="route('sites.show', s.id)"
                                class="text-[11px] px-2 py-0.5 rounded-md bg-white/5 text-zinc-400 border border-white/5 hover:bg-white/10 hover:text-zinc-200 transition-colors truncate max-w-[120px]"
                                :title="s.name"
                            >{{ s.name }}</Link>
                        </div>
                    </div>
                </div>

                <div v-else class="flex-1 flex items-center justify-center py-8">
                    <span class="text-sm text-zinc-600">{{ site.server ? 'No metrics yet' : 'No server linked' }}</span>
                </div>

                <!-- Footer link (only when server linked) -->
                <div v-if="site.server" class="mt-4 pt-4 border-t border-white/5">
                    <Link
                        :href="route('servers.show', site.server.id)"
                        class="text-xs text-emerald-500 hover:text-emerald-400 transition-colors"
                    >
                        Server history &rarr;
                    </Link>
                </div>
            </GlassCard>
        </div>

        <!-- ─── History & Incidents timeline ─────────────────────────────── -->
        <div id="timeline">
            <GlassCard :padding="5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-white">History &amp; Incidents</h2>
                    <span class="text-[10px] text-zinc-600 uppercase tracking-wide">30d</span>
                </div>

                <div v-if="sortedTimeline.length === 0" class="py-8 text-center text-sm text-zinc-600">
                    No incidents or insights in the last 30 days
                </div>

                <div v-else class="relative">
                    <!-- Vertical guide line -->
                    <div class="absolute left-[9px] top-2 bottom-2 w-px bg-white/5" aria-hidden="true" />

                    <ul class="space-y-0" role="list">
                        <li
                            v-for="(item, idx) in sortedTimeline"
                            :key="idx"
                            class="relative pl-7"
                        >
                            <!-- Timeline dot -->
                            <span
                                class="absolute left-0 top-3 w-[18px] h-[18px] rounded-full flex items-center justify-center"
                                :class="item.kind === 'incident'
                                    ? (item.resolved_at ? 'bg-zinc-700/50' : 'bg-red-500/20')
                                    : 'bg-amber-500/10'"
                            >
                                <span
                                    class="w-1.5 h-1.5 rounded-full"
                                    :class="item.kind === 'incident'
                                        ? (item.resolved_at ? 'bg-zinc-500' : 'bg-red-500')
                                        : (item.severity === 'critical' ? 'bg-red-400' : item.severity === 'warning' ? 'bg-amber-400' : 'bg-zinc-400')"
                                />
                            </span>

                            <!-- Content row -->
                            <div class="flex items-start justify-between gap-3 py-2.5 border-b border-white/[0.03] last:border-0">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <!-- Kind badge -->
                                        <span
                                            class="shrink-0 text-[9px] font-bold uppercase px-1.5 py-0.5 rounded border"
                                            :class="item.kind === 'incident' ? 'bg-red-500/10 text-red-400 border-red-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20'"
                                        >{{ item.kind }}</span>
                                        <!-- Title -->
                                        <component
                                            :is="item.url ? Link : 'span'"
                                            v-bind="item.url ? { href: item.url } : {}"
                                            class="text-sm text-zinc-200 hover:text-white transition-colors truncate"
                                            :class="item.url ? 'hover:text-white' : ''"
                                        >{{ item.title }}</component>
                                    </div>
                                    <!-- Duration for incidents -->
                                    <div v-if="item.kind === 'incident'" class="text-[11px] text-zinc-600 mt-0.5">
                                        <span v-if="!item.resolved_at" class="text-red-400">Ongoing</span>
                                        <span v-else>
                                            Resolved after {{ Math.round((new Date(item.resolved_at).getTime() - new Date(item.at).getTime()) / 60_000) }} min
                                        </span>
                                    </div>
                                </div>
                                <!-- Timestamp -->
                                <span class="shrink-0 text-[11px] text-zinc-600 tabular-nums">{{ formatDate(item.at) }}</span>
                            </div>
                        </li>
                    </ul>
                </div>
            </GlassCard>
        </div>

    </div>
</template>
