<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import GlassCard from '@/Components/GlassCard.vue'
import EmptyState from '@/Components/EmptyState.vue'
import UptimeStrip from '@/Components/Overview/UptimeStrip.vue'

interface BreakdownDimension {
    score: number
    weight: number
    available?: boolean
    value?: number
    position?: number
    performance?: number
    p95_ms?: number
}

interface DomainCounts {
    availability: number
    seo_business: number
    infrastructure: number
}

interface Coverage {
    available: string[]
    missing: string[]
    ratio: number
}

interface TimelineDay {
    date: string
    status: 'up' | 'partial' | 'down' | 'no_data'
    uptime: number | null
    incidents: number
}

interface SiteHealth {
    monitor_id: number
    site: string
    name: string
    score: number
    grade: string
    trend: 'up' | 'down' | 'flat'
    coverage?: Coverage
    breakdown: {
        uptime: BreakdownDimension
        seo: BreakdownDimension
        perf: BreakdownDimension
        ttfb: BreakdownDimension
    }
    domain_counts?: DomainCounts | null
    // Merged by DashboardOverviewService — see DashboardController.
    status?: 'up' | 'down' | 'pending'
    last_checked_at?: string | null
    down_since?: string | null
    uptime_30d?: number | null
    timeline?: TimelineDay[]
}

interface HealthData {
    sites: SiteHealth[]
    computed_at: string
}

const props = defineProps<{
    health: HealthData
}>()

// ------------------------------------------------------------------
// Score badge colour — console rule: calm grey is the default, colour
// only marks a problem (amber/red), never a decorative "everything is
// emerald" state.
// ------------------------------------------------------------------
const scoreColorClass = (score: number): string => {
    if (score >= 80) return 'text-[var(--color-text-primary)] border-[var(--color-border)]'
    if (score >= 60) return 'text-[var(--color-warning)] border-[var(--color-warning)]/30'
    return 'text-[var(--color-danger)] border-[var(--color-danger)]/30'
}

const dimensionColorClass = (score: number): string => {
    if (score >= 80) return 'text-[var(--color-text-secondary)]'
    if (score >= 60) return 'text-[var(--color-warning)]'
    return 'text-[var(--color-danger)]'
}

const computedAtLabel = computed(() => {
    if (!props.health.computed_at) return null
    const ms = Date.now() - new Date(props.health.computed_at).getTime()
    const m = Math.floor(ms / 60000)
    if (m < 1) return 'Just now'
    if (m < 60) return `${m}m ago`
    if (m < 1440) return `${Math.floor(m / 60)}h ago`
    return `${Math.floor(m / 1440)}d ago`
})

const timeAgo = (iso: string | null | undefined): string => {
    if (!iso) return 'never'
    const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (s < 5) return 'just now'
    if (s < 60) return `${s}s ago`
    const m = Math.floor(s / 60)
    if (m < 60) return `${m}m ago`
    if (m < 1440) return `${Math.floor(m / 60)}h ago`
    return `${Math.floor(m / 1440)}d ago`
}

// Named dimensions in clear text — never the cryptic U/S/P/T initials. A
// dimension with no data reads "—", it is never averaged into a fake score.
const DIMENSIONS: Array<{ key: 'uptime' | 'seo' | 'perf' | 'ttfb'; label: string }> = [
    { key: 'uptime', label: 'Uptime' },
    { key: 'seo', label: 'SEO' },
    { key: 'perf', label: 'Performance' },
    { key: 'ttfb', label: 'TTFB' },
]

// A score built on a single dimension (uptime only, most commonly) is still
// a fair number, but a bare grade letter next to it implies four signals
// backed it — say plainly which one did instead.
const soleDimension = (site: SiteHealth): string | null => {
    const available = site.coverage?.available ?? []
    if (available.length !== 1) return null

    return DIMENSIONS.find((d) => d.key === available[0])?.label ?? null
}

const coverageTitle = (site: SiteHealth): string => {
    const missing = site.coverage?.missing ?? []
    const labels = missing.map((key) => DIMENSIONS.find((d) => d.key === key)?.label ?? key)

    return `No data for ${labels.join(', ')} — their weight was redistributed across the dimensions that do have data.`
}

const hasDomainCounts = (counts: DomainCounts | null | undefined): counts is DomainCounts => {
    if (!counts) return false
    return counts.availability > 0 || counts.seo_business > 0 || counts.infrastructure > 0
}

const stateLabel = (site: SiteHealth): string => {
    switch (site.status) {
        case 'down': return 'Down'
        case 'pending': return 'Pending'
        default: return 'Up'
    }
}

const stateDotClass = (site: SiteHealth): string => {
    switch (site.status) {
        case 'down': return 'status-dot offline'
        case 'pending': return 'status-dot'
        default: return 'status-dot online'
    }
}

const stateTextClass = (site: SiteHealth): string => {
    switch (site.status) {
        case 'down': return 'text-[var(--color-danger)]'
        case 'pending': return 'text-[var(--color-text-muted)]'
        default: return 'text-[var(--color-text-secondary)]'
    }
}
</script>

<template>
    <GlassCard title="Sites">
        <template #actions>
            <span v-if="computedAtLabel" class="text-xs text-[var(--color-text-muted)]">
                Updated {{ computedAtLabel }}
            </span>
        </template>

        <EmptyState
            v-if="health.sites.length === 0"
            title="No sites yet"
            description="Add monitors to start tracking portfolio health scores."
            icon="globe"
        />

        <!-- Mobile: stacked rows (state + name + health / full-width strip / uptime · last check · dimensions) — the
             table's six columns don't fit a 390px viewport without truncating Uptime, Last check and Health. -->
        <div v-if="health.sites.length > 0" class="md:hidden divide-y divide-[var(--color-border)]">
            <div v-for="site in health.sites" :key="site.monitor_id" class="py-3">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <span :class="stateDotClass(site)" class="flex-shrink-0" />
                        <Link
                            :href="route('monitors.show', site.monitor_id)"
                            class="text-sm font-medium text-[var(--color-text-primary)] hover:text-[var(--color-primary-400)] transition-colors truncate"
                        >
                            {{ site.name }}
                        </Link>
                    </div>
                    <div class="flex-shrink-0 inline-flex items-center gap-1.5 font-mono">
                        <span class="text-base font-semibold tabular-nums" :class="scoreColorClass(site.score)">{{ site.score }}</span>
                        <span
                            v-if="!soleDimension(site)"
                            class="text-[10px] font-semibold uppercase px-1 py-0.5 rounded border"
                            :class="scoreColorClass(site.score)"
                        >{{ site.grade }}</span>
                    </div>
                </div>

                <div class="mt-2">
                    <UptimeStrip v-if="site.timeline?.length" :days="site.timeline" class="w-full [&>span]:flex-1" />
                    <span v-else class="text-xs text-[var(--color-text-faint)]">—</span>
                </div>

                <div class="mt-2 flex items-center gap-2.5 flex-wrap text-[11px] font-mono">
                    <span class="text-[var(--color-text-muted)]">
                        <span :class="stateTextClass(site)">{{ site.uptime_30d != null ? `${site.uptime_30d.toFixed(2)}%` : '—' }}</span> uptime
                    </span>
                    <span class="text-[var(--color-text-faint)]">·</span>
                    <span class="text-[var(--color-text-muted)]">{{ timeAgo(site.last_checked_at) }}</span>
                    <span class="text-[var(--color-text-faint)]">·</span>
                    <span
                        v-for="dim in DIMENSIONS"
                        :key="dim.key"
                        :class="site.breakdown[dim.key].available === false ? 'text-[var(--color-text-faint)]' : dimensionColorClass(site.breakdown[dim.key].score)"
                        :title="dim.label"
                    >
                        {{ dim.label }} {{ site.breakdown[dim.key].available === false ? '—' : site.breakdown[dim.key].score }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Desktop / tablet: full table -->
        <div v-if="health.sites.length > 0" class="hidden md:block overflow-x-auto -mx-6 px-6">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="border-b border-[var(--color-border)]">
                        <th class="section-label text-left font-medium pr-3 py-2.5 whitespace-nowrap">State</th>
                        <th class="section-label text-left font-medium px-3 py-2.5">Site</th>
                        <th class="section-label text-left font-medium px-3 py-2.5 whitespace-nowrap">30d</th>
                        <th class="section-label text-right font-medium px-3 py-2.5 whitespace-nowrap">Uptime</th>
                        <th class="section-label text-left font-medium px-3 py-2.5 whitespace-nowrap">Last check</th>
                        <th class="section-label text-right font-medium pl-3 py-2.5 whitespace-nowrap">Health</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="site in health.sites"
                        :key="site.monitor_id"
                        class="border-b border-[var(--color-border)] last:border-0 hover:bg-[var(--color-surface-1)] transition-colors"
                    >
                        <!-- State -->
                        <td class="pr-3 py-3 align-top whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <span :class="stateDotClass(site)" />
                                <span class="text-xs font-medium" :class="stateTextClass(site)">{{ stateLabel(site) }}</span>
                            </div>
                        </td>

                        <!-- Site name + breakdown -->
                        <td class="px-3 py-3 align-top min-w-0">
                            <Link
                                :href="route('monitors.show', site.monitor_id)"
                                class="text-sm font-medium text-[var(--color-text-primary)] hover:text-[var(--color-primary-400)] transition-colors truncate block"
                            >
                                {{ site.name }}
                            </Link>
                            <p class="text-xs text-[var(--color-text-faint)] font-mono truncate mt-0.5">{{ site.site }}</p>

                            <!-- Named dimensions, in clear text, never cryptic initials -->
                            <div class="flex items-center gap-2.5 mt-1.5 flex-wrap text-[11px] font-mono">
                                <span
                                    v-for="dim in DIMENSIONS"
                                    :key="dim.key"
                                    :class="site.breakdown[dim.key].available === false ? 'text-[var(--color-text-faint)]' : dimensionColorClass(site.breakdown[dim.key].score)"
                                    :title="dim.label"
                                >
                                    {{ dim.label }} {{ site.breakdown[dim.key].available === false ? '—' : site.breakdown[dim.key].score }}
                                </span>
                            </div>

                            <div
                                v-if="hasDomainCounts(site.domain_counts)"
                                class="flex items-center gap-1.5 mt-1.5 flex-wrap"
                            >
                                <span
                                    v-if="site.domain_counts!.availability > 0"
                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold text-[var(--color-danger)] border border-[var(--color-danger)]/20"
                                    title="Availability insights"
                                >
                                    {{ site.domain_counts!.availability }} availability
                                </span>
                                <span
                                    v-if="site.domain_counts!.seo_business > 0"
                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold text-[var(--color-info)] border border-[var(--color-info)]/20"
                                    title="SEO & Business insights"
                                >
                                    {{ site.domain_counts!.seo_business }} SEO
                                </span>
                                <span
                                    v-if="site.domain_counts!.infrastructure > 0"
                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold text-[var(--color-warning)] border border-[var(--color-warning)]/20"
                                    title="Infrastructure insights"
                                >
                                    {{ site.domain_counts!.infrastructure }} infra
                                </span>
                            </div>
                        </td>

                        <!-- 30-day uptime strip -->
                        <td class="px-3 py-3 align-top">
                            <UptimeStrip v-if="site.timeline?.length" :days="site.timeline" />
                            <span v-else class="text-xs text-[var(--color-text-faint)]">—</span>
                        </td>

                        <!-- Uptime % -->
                        <td class="px-3 py-3 align-top text-right whitespace-nowrap">
                            <span class="text-sm font-mono tabular-nums" :class="stateTextClass(site)">
                                {{ site.uptime_30d != null ? `${site.uptime_30d.toFixed(2)}%` : '—' }}
                            </span>
                        </td>

                        <!-- Last check -->
                        <td class="px-3 py-3 align-top whitespace-nowrap">
                            <span class="text-xs text-[var(--color-text-muted)] font-mono">{{ timeAgo(site.last_checked_at) }}</span>
                        </td>

                        <!-- Health score -->
                        <td class="pl-3 py-3 align-top text-right whitespace-nowrap">
                            <div class="inline-flex flex-col items-end">
                                <span class="inline-flex items-center gap-1.5 font-mono">
                                    <span class="text-base font-semibold tabular-nums" :class="scoreColorClass(site.score)">{{ site.score }}</span>
                                    <span
                                        v-if="!soleDimension(site)"
                                        class="text-[10px] font-semibold uppercase px-1 py-0.5 rounded border"
                                        :class="scoreColorClass(site.score)"
                                    >{{ site.grade }}</span>
                                </span>
                                <span
                                    v-if="soleDimension(site)"
                                    class="text-[10px] text-[var(--color-text-faint)]"
                                    :title="coverageTitle(site)"
                                >{{ soleDimension(site) }} only</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </GlassCard>
</template>
