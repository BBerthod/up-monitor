<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import GlassCard from '@/Components/GlassCard.vue'

// ─── Types ───────────────────────────────────────────────────────────────────

interface HealthSite {
    name: string
    score: number | null
    grade?: string | null
    trend?: 'up' | 'down' | 'stable' | null
}

interface Opportunity {
    title: string
    estimated_gain: number | null
    query: string | null
    site?: { name?: string } | null
}

interface ServerHealth {
    server: string | null
    metric: string | null
    severity: string
    value: number | null
    sites: string[]
}

interface WhatChanged {
    label?: string
    site?: string
    delta_pct?: number
    direction?: string
}

interface ActionItem {
    action: string
    priority_score: number
    insight_id?: number
    site?: string
    severity?: string
    title?: string
    effort?: number
    impact?: number
}

interface ActionPlan {
    actions: ActionItem[]
    total_actionable: number
    generated_at: string
}

interface Report {
    overall_uptime: number | null
    incident_count: number | null
    period_start?: string
    period_end?: string
}

interface Alerts {
    core_update_suspected: boolean
    ga4_broken_sites: string[]
}

interface DigestPayload {
    team_name?: string
    period_start?: string
    period_end?: string
    narrative?: string | null
    health?: HealthSite[]
    what_changed?: WhatChanged[]
    top_opportunities?: Opportunity[]
    server_health?: ServerHealth[]
    report?: Report | null
    alerts?: Alerts | null
    action_plan?: ActionPlan | null
}

const props = defineProps<{
    digest?: DigestPayload | null
    generated_at?: string | null
}>()

// ─── Helpers ─────────────────────────────────────────────────────────────────

function timeAgo(iso: string | null | undefined): string {
    if (!iso) return ''
    const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60_000)
    if (m < 1) return 'just now'
    if (m < 60) return `${m}m ago`
    if (m < 1440) return `${Math.floor(m / 60)}h ago`
    const d = Math.floor(m / 1440)
    return d === 1 ? 'yesterday' : `${d}d ago`
}

function healthColor(score: number | null | undefined): string {
    if (score == null) return 'text-zinc-500'
    if (score >= 80) return 'text-emerald-400'
    if (score >= 50) return 'text-amber-400'
    return 'text-red-400'
}

function trendIcon(trend: string | null | undefined): string {
    if (trend === 'up') return '▲'
    if (trend === 'down') return '▼'
    return '—'
}

function trendColor(trend: string | null | undefined): string {
    if (trend === 'up') return 'text-emerald-400'
    if (trend === 'down') return 'text-red-400'
    return 'text-zinc-600'
}

function severityClass(sev: string | null | undefined): string {
    if (sev === 'critical') return 'text-red-400 bg-red-500/10 border-red-500/20'
    if (sev === 'warning') return 'text-amber-400 bg-amber-500/10 border-amber-500/20'
    return 'text-zinc-400 bg-zinc-500/10 border-zinc-500/20'
}

// Narrative arrives as a string with bullet points separated by \n.
// Split on newline and render each non-empty line as a bullet.
const narrativeLines = computed(() => {
    const raw = props.digest?.narrative ?? ''
    return raw
        .split('\n')
        .map(l => l.replace(/^[\s•\-*]+/, '').trim())
        .filter(l => l.length > 0)
})

const digest = computed(() => props.digest ?? null)
const generatedAt = computed(() => props.generated_at ?? null)

const healthSites = computed(() => digest.value?.health ?? [])
const topOpportunities = computed(() => digest.value?.top_opportunities ?? [])
const serverHealth = computed(() => digest.value?.server_health ?? [])
const actionPlan = computed(() => digest.value?.action_plan ?? null)
const alerts = computed(() => digest.value?.alerts ?? null)
const report = computed(() => digest.value?.report ?? null)

const periodLabel = computed(() => {
    const d = digest.value
    if (!d?.period_start || !d?.period_end) return null
    const fmt = (s: string) => {
        try {
            return new Date(s).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
        } catch { return s }
    }
    return `${fmt(d.period_start)} – ${fmt(d.period_end)}`
})
</script>

<template>
    <Head title="Weekly Digest" />

    <div class="space-y-8">
        <PageHeader
            title="Weekly Digest"
            :description="periodLabel ?? 'AI-narrated summary of your portfolio health'"
        >
            <template #actions>
                <span v-if="generatedAt" class="text-xs text-zinc-600 font-mono">
                    {{ timeAgo(generatedAt) }}
                </span>
            </template>
        </PageHeader>

        <!-- ─── No digest yet ─────────────────────────────────────────────── -->
        <div
            v-if="!digest"
            class="flex flex-col items-center justify-center py-16 text-center border border-dashed border-white/5 rounded-2xl bg-white/[0.02] space-y-5"
        >
            <div class="p-4 rounded-full bg-zinc-800">
                <svg class="w-8 h-8 text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
            </div>
            <div>
                <h4 class="text-lg font-semibold text-white">No digest available yet</h4>
                <p class="text-sm text-zinc-500 mt-1 max-w-sm mx-auto">
                    The weekly digest is generated every Monday at 09:00. Check back then.
                </p>
            </div>
        </div>

        <template v-else>
            <!-- ─── Alert banners ─────────────────────────────────────────── -->
            <div v-if="alerts" class="space-y-2">
                <div
                    v-if="alerts.core_update_suspected"
                    class="flex items-start gap-3 px-4 py-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300"
                >
                    <svg class="w-4 h-4 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <span class="text-sm font-medium">Google core update suspected this week</span>
                </div>

                <div
                    v-if="alerts.ga4_broken_sites?.length"
                    class="flex items-start gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-300"
                >
                    <svg class="w-4 h-4 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span class="text-sm">
                        <strong class="font-semibold text-red-200">GA4 broken</strong>
                        on {{ alerts.ga4_broken_sites.join(', ') }}
                    </span>
                </div>
            </div>

            <!-- ─── AI Narrative ──────────────────────────────────────────── -->
            <GlassCard v-if="narrativeLines.length > 0" title="AI Summary" :padding="5">
                <ul class="space-y-2.5">
                    <li
                        v-for="(line, i) in narrativeLines"
                        :key="i"
                        class="flex items-start gap-3 text-sm text-zinc-300 leading-relaxed"
                    >
                        <span class="mt-1.5 flex-shrink-0 w-1.5 h-1.5 rounded-full bg-emerald-500/60" aria-hidden="true" />
                        <span>{{ line }}</span>
                    </li>
                </ul>
            </GlassCard>

            <!-- ─── Portfolio overview strip ──────────────────────────────── -->
            <div v-if="report" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl border border-white/5 bg-white/[0.02] text-center">
                    <div class="text-3xl font-bold tabular-nums" :class="(report.overall_uptime ?? 0) >= 99 ? 'text-emerald-400' : 'text-amber-400'">
                        {{ report.overall_uptime != null ? report.overall_uptime.toFixed(2) + '%' : '—' }}
                    </div>
                    <div class="text-xs text-zinc-500 mt-1 uppercase tracking-wider">Overall uptime</div>
                </div>
                <div class="p-4 rounded-xl border border-white/5 bg-white/[0.02] text-center">
                    <div class="text-3xl font-bold tabular-nums" :class="(report.incident_count ?? 0) > 0 ? 'text-red-400' : 'text-emerald-400'">
                        {{ report.incident_count ?? '—' }}
                    </div>
                    <div class="text-xs text-zinc-500 mt-1 uppercase tracking-wider">Incidents</div>
                </div>
                <div class="p-4 rounded-xl border border-white/5 bg-white/[0.02] text-center sm:col-auto col-span-2">
                    <div class="text-3xl font-bold tabular-nums text-zinc-200">
                        {{ healthSites.length }}
                    </div>
                    <div class="text-xs text-zinc-500 mt-1 uppercase tracking-wider">Sites tracked</div>
                </div>
            </div>

            <!-- ─── Health scores ─────────────────────────────────────────── -->
            <GlassCard v-if="healthSites.length > 0" title="Site Health" :padding="5">
                <ul class="divide-y divide-white/[0.03]">
                    <li
                        v-for="site in healthSites"
                        :key="site.name"
                        class="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
                    >
                        <span class="text-sm text-zinc-300 font-mono truncate min-w-0">{{ site.name }}</span>
                        <div class="flex items-center gap-3 shrink-0">
                            <span v-if="site.grade" class="text-xs text-zinc-600 uppercase font-semibold">{{ site.grade }}</span>
                            <span
                                class="text-xs font-semibold tabular-nums"
                                :class="trendColor(site.trend)"
                                :aria-label="`Trend: ${site.trend}`"
                            >{{ trendIcon(site.trend) }}</span>
                            <span class="text-sm font-bold tabular-nums min-w-[2.5rem] text-right" :class="healthColor(site.score)">
                                {{ site.score ?? '—' }}
                            </span>
                        </div>
                    </li>
                </ul>
            </GlassCard>

            <!-- ─── Top opportunities ─────────────────────────────────────── -->
            <GlassCard v-if="topOpportunities.length > 0" title="Top SEO Opportunities" :padding="5">
                <ul class="divide-y divide-white/[0.03]">
                    <li
                        v-for="(opp, i) in topOpportunities"
                        :key="i"
                        class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <span class="shrink-0 w-5 h-5 flex items-center justify-center rounded bg-transparent text-zinc-400 text-[10px] font-bold border border-zinc-800">
                            {{ i + 1 }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-white leading-snug">{{ opp.title }}</p>
                            <div class="flex items-center gap-2 mt-0.5 flex-wrap text-xs text-zinc-500">
                                <span v-if="opp.query" class="font-mono truncate max-w-[180px]">{{ opp.query }}</span>
                                <span v-if="opp.site?.name" class="text-zinc-600">{{ opp.site.name }}</span>
                            </div>
                        </div>
                        <span v-if="opp.estimated_gain != null" class="shrink-0 text-sm font-semibold text-emerald-400 tabular-nums">
                            +{{ Math.round(opp.estimated_gain) }}
                        </span>
                    </li>
                </ul>
                <div class="mt-4 pt-3 border-t border-white/5">
                    <Link :href="route('striking-distance.index')" class="text-xs text-emerald-500 hover:text-emerald-400 transition-colors">
                        View all striking-distance keywords &rarr;
                    </Link>
                </div>
            </GlassCard>

            <!-- ─── Server health warnings ─────────────────────────────────── -->
            <GlassCard v-if="serverHealth.length > 0" title="Server Health Alerts" :padding="5">
                <ul class="space-y-2">
                    <li
                        v-for="(srv, i) in serverHealth"
                        :key="i"
                        class="flex items-start gap-3 p-3 rounded-lg border"
                        :class="severityClass(srv.severity)"
                    >
                        <svg class="w-4 h-4 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/>
                            <rect x="2" y="14" width="20" height="8" rx="2" ry="2"/>
                            <line x1="6" y1="6" x2="6.01" y2="6"/>
                            <line x1="6" y1="18" x2="6.01" y2="18"/>
                        </svg>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium">{{ srv.server ?? 'Unknown server' }}</span>
                                <span v-if="srv.metric" class="text-xs font-mono uppercase">{{ srv.metric }}</span>
                                <span v-if="srv.value != null" class="text-xs tabular-nums">{{ srv.value }}%</span>
                            </div>
                            <p v-if="srv.sites?.length" class="text-xs mt-0.5 opacity-75">
                                Affects: {{ srv.sites.join(', ') }}
                            </p>
                        </div>
                    </li>
                </ul>
                <div class="mt-4 pt-3 border-t border-white/5">
                    <Link :href="route('servers.index')" class="text-xs text-emerald-500 hover:text-emerald-400 transition-colors">
                        View servers &rarr;
                    </Link>
                </div>
            </GlassCard>

            <!-- ─── Action plan top items ─────────────────────────────────── -->
            <GlassCard
                v-if="actionPlan?.actions?.length"
                title="Priority Actions"
                :padding="5"
            >
                <template #actions>
                    <span class="text-xs text-zinc-500">
                        {{ actionPlan.total_actionable }} actionable total
                    </span>
                </template>

                <ul class="divide-y divide-white/[0.03]">
                    <li
                        v-for="(action, i) in actionPlan.actions.slice(0, 5)"
                        :key="i"
                        class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <span class="shrink-0 w-5 h-5 flex items-center justify-center rounded bg-transparent border border-zinc-800 text-[10px] font-bold text-zinc-400">
                            {{ i + 1 }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-zinc-200 leading-snug">{{ action.action }}</p>
                            <div class="flex items-center gap-2 mt-0.5 text-xs text-zinc-600">
                                <span v-if="action.site">{{ action.site }}</span>
                                <span v-if="action.severity" class="capitalize">{{ action.severity }}</span>
                            </div>
                        </div>
                        <span class="shrink-0 text-xs font-mono text-zinc-600 tabular-nums pt-0.5">
                            {{ Math.round(action.priority_score) }}
                        </span>
                    </li>
                </ul>

                <div class="mt-4 pt-3 border-t border-white/5">
                    <Link :href="route('action-plan.index')" class="text-xs text-emerald-500 hover:text-emerald-400 transition-colors">
                        Full action plan &rarr;
                    </Link>
                </div>
            </GlassCard>
        </template>
    </div>
</template>
