<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import GlassCard from '@/Components/GlassCard.vue'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'

// ─── Types ───────────────────────────────────────────────────────────────────

interface SiteKpis {
    gsc_clicks_28d: number | null
    gsc_impressions_28d: number | null
    gsc_position_28d: number | null
    bing_clicks_28d: number | null
    ga4_users_28d: number | null
    ttfb: number | null
}

interface SiteHealthScore {
    score: number
    grade: string
    trend: string
}

interface SiteRow {
    id: number
    name: string
    domain: string
    kpis?: SiteKpis | null
    health_score?: SiteHealthScore | null
}

interface LighthouseCard {
    monitor_id: number
    monitor_name: string
    site_name: string | null
    performance: number | null
    seo: number | null
    accessibility: number | null
    best_practices: number | null
    scored_at: string | null
}

const props = defineProps<{
    sites?: SiteRow[] | null
    lighthouse?: LighthouseCard[] | null
}>()

useRealtimeUpdates({
    onInsightChanged: ['sites', 'lighthouse'],
    onLighthouseCompleted: ['sites', 'lighthouse'],
    onMonitorChecked: ['sites'],
})

// ─── Helpers ─────────────────────────────────────────────────────────────────

function fmt(n: number | null | undefined): string {
    if (n == null) return '—'
    return n.toLocaleString()
}

function fmtPos(n: number | null | undefined): string {
    if (n == null) return '—'
    return '#' + n.toFixed(1)
}

function fmtMs(n: number | null | undefined): string {
    if (n == null) return '—'
    return Math.round(n) + ' ms'
}

function healthColor(score: number | null | undefined): string {
    if (score == null) return 'text-zinc-500 bg-zinc-500/10 border-zinc-500/20'
    if (score >= 90) return 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20'
    if (score >= 50) return 'text-amber-400 bg-amber-500/10 border-amber-500/20'
    return 'text-red-400 bg-red-500/10 border-red-500/20'
}

function ttfbColor(ms: number | null | undefined): string {
    if (ms == null) return 'text-zinc-500'
    if (ms < 800) return 'text-emerald-400'
    if (ms < 2000) return 'text-amber-400'
    return 'text-red-400'
}

// Lighthouse score colour — 90/50 thresholds matching the rest of the app
function lhColor(v: number | null | undefined): string {
    if (v == null) return '#52525b'
    if (v >= 90) return '#0cce6b'
    if (v >= 50) return '#ffa400'
    return '#ff4e42'
}

const sites = props.sites ?? []
const lighthouseCards = props.lighthouse ?? []
</script>

<template>
    <Head title="KPI & Trends" />

    <div class="space-y-10">
        <PageHeader
            title="KPI & Trends"
            description="Search Console, GA4, Bing and TTFB metrics across all sites"
        />

        <!-- ─── Sites table ──────────────────────────────────────────────────── -->
        <section>
            <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 mb-3">Sites — 28 day overview</h2>

            <div v-if="sites.length > 0" class="overflow-x-auto rounded-xl border border-white/5">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5 bg-white/[0.02]">
                            <th class="text-left px-4 py-3 font-medium text-zinc-400 whitespace-nowrap">Site</th>
                            <th class="text-right px-4 py-3 font-medium text-zinc-400 whitespace-nowrap">GSC clicks</th>
                            <th class="text-right px-4 py-3 font-medium text-zinc-400 whitespace-nowrap">GSC impr.</th>
                            <th class="text-right px-4 py-3 font-medium text-zinc-400 whitespace-nowrap">Position</th>
                            <th class="text-right px-4 py-3 font-medium text-zinc-400 whitespace-nowrap">Bing</th>
                            <th class="text-right px-4 py-3 font-medium text-zinc-400 whitespace-nowrap">GA4 users</th>
                            <th class="text-right px-4 py-3 font-medium text-zinc-400 whitespace-nowrap">TTFB p75</th>
                            <th class="text-right px-4 py-3 font-medium text-zinc-400 whitespace-nowrap">Health</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.03]">
                        <tr
                            v-for="site in sites"
                            :key="site.id"
                            class="group hover:bg-white/[0.02] transition-colors"
                        >
                            <!-- Site name + link to cockpit -->
                            <td class="px-4 py-3">
                                <Link
                                    :href="route('sites.show', site.id)"
                                    class="font-medium text-zinc-200 hover:text-white transition-colors truncate max-w-[180px] block"
                                    :title="site.domain"
                                >
                                    {{ site.name }}
                                </Link>
                                <span class="text-[11px] text-zinc-600 font-mono">{{ site.domain }}</span>
                            </td>

                            <!-- GSC clicks -->
                            <td class="px-4 py-3 text-right font-mono text-zinc-300 tabular-nums">
                                {{ fmt(site.kpis?.gsc_clicks_28d) }}
                            </td>

                            <!-- GSC impressions -->
                            <td class="px-4 py-3 text-right font-mono text-zinc-500 tabular-nums">
                                {{ fmt(site.kpis?.gsc_impressions_28d) }}
                            </td>

                            <!-- Average position -->
                            <td class="px-4 py-3 text-right">
                                <span class="font-mono tabular-nums" :class="site.kpis?.gsc_position_28d != null ? 'text-amber-400' : 'text-zinc-600'">
                                    {{ fmtPos(site.kpis?.gsc_position_28d) }}
                                </span>
                            </td>

                            <!-- Bing clicks -->
                            <td class="px-4 py-3 text-right font-mono text-zinc-500 tabular-nums">
                                {{ fmt(site.kpis?.bing_clicks_28d) }}
                            </td>

                            <!-- GA4 users -->
                            <td class="px-4 py-3 text-right font-mono text-zinc-300 tabular-nums">
                                {{ fmt(site.kpis?.ga4_users_28d) }}
                            </td>

                            <!-- TTFB p75 -->
                            <td class="px-4 py-3 text-right">
                                <span class="font-mono tabular-nums text-xs" :class="ttfbColor(site.kpis?.ttfb)">
                                    {{ fmtMs(site.kpis?.ttfb) }}
                                </span>
                            </td>

                            <!-- Health score badge -->
                            <td class="px-4 py-3 text-right">
                                <span
                                    v-if="site.health_score != null"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold border tabular-nums"
                                    :class="healthColor(site.health_score.score)"
                                >
                                    {{ site.health_score.score }}<span class="opacity-60">&nbsp;· {{ site.health_score.grade }}</span>
                                </span>
                                <span v-else class="text-zinc-700 text-xs">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Empty state -->
            <div
                v-else
                class="flex flex-col items-center justify-center py-16 border border-dashed border-white/5 rounded-xl text-center"
            >
                <svg class="w-8 h-8 text-zinc-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <path d="M3 9h18"/>
                    <path d="M9 21V9"/>
                </svg>
                <p class="text-sm text-zinc-500">No sites configured yet.</p>
                <Link :href="route('sites.index')" class="mt-3 text-xs text-emerald-500 hover:text-emerald-400 transition-colors">
                    Add your first site &rarr;
                </Link>
            </div>
        </section>

        <!-- ─── Lighthouse grid ──────────────────────────────────────────────── -->
        <section v-if="lighthouseCards.length > 0">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 mb-3">Lighthouse scores</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <GlassCard
                    v-for="card in lighthouseCards"
                    :key="card.monitor_id"
                    :padding="4"
                >
                    <!-- Card header -->
                    <div class="flex items-start justify-between gap-2 mb-4">
                        <div class="min-w-0">
                            <Link
                                :href="route('monitors.show', card.monitor_id)"
                                class="text-sm font-medium text-zinc-200 hover:text-white transition-colors truncate block"
                            >
                                {{ card.monitor_name }}
                            </Link>
                            <span v-if="card.site_name" class="text-[11px] text-zinc-600">{{ card.site_name }}</span>
                        </div>
                    </div>

                    <!-- 4 mini scores in a 2×2 grid -->
                    <div class="grid grid-cols-4 gap-1.5">
                        <div
                            v-for="item in [
                                { label: 'Perf', value: card.performance },
                                { label: 'SEO',  value: card.seo },
                                { label: 'A11y', value: card.accessibility },
                                { label: 'BP',   value: card.best_practices },
                            ]"
                            :key="item.label"
                            class="flex flex-col items-center p-2 rounded-lg bg-white/[0.03] border border-white/5"
                        >
                            <span
                                class="text-base font-bold tabular-nums leading-none"
                                :style="{ color: lhColor(item.value) }"
                            >
                                {{ item.value != null ? item.value : '—' }}
                            </span>
                            <span class="text-[9px] uppercase tracking-wider text-zinc-600 mt-1">{{ item.label }}</span>
                        </div>
                    </div>

                    <!-- Last scored timestamp -->
                    <div v-if="card.scored_at" class="mt-2 text-[10px] text-zinc-700 text-right font-mono">
                        {{ new Date(card.scored_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) }}
                    </div>
                </GlassCard>
            </div>
        </section>
    </div>
</template>
