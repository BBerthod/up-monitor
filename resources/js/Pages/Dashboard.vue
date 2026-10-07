<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import { usePageLoading } from '@/Composables/usePageLoading'
import PageHeader from '@/Components/PageHeader.vue'
import SlaProgressBar from '@/Components/SlaProgressBar.vue'
import HealthScorePortfolio from '@/Components/HealthScorePortfolio.vue'
import SkeletonDashboard from '@/Components/SkeletonDashboard.vue'
import TriageBanner from '@/Components/Triage/TriageBanner.vue'
import DomainHealthCard from '@/Components/Overview/DomainHealthCard.vue'
import type { TriageItemData } from '@/Components/Triage/TriageItem.vue'

// ─── Types ────────────────────────────────────────────────────────────────────

interface TriageCounts {
    total: number
    critical: number
    domains?: Record<string, number>
}

interface TriageBreakdown {
    warnings?: number
    info_opportunity?: number
}

interface TriageData {
    items: TriageItemData[]
    counts: TriageCounts
    breakdown: TriageBreakdown
}

interface AvailabilityHealth {
    monitors_total: number
    monitors_up: number
    incidents_open: number
    uptime_30d: number
    worst_monitor?: { id: number; name: string; status: string } | null
}

interface SeoHealth {
    health_avg: number | null
    quick_wins: number
    worst_site?: { id: number; name: string; score: number } | null
}

interface InfraHealth {
    worst_server?: { id: number; name: string; cpu: number; ram: number; disk: number; load_1: number } | null
    servers_total: number
    servers_silent: number
}

interface AlertingHealth {
    channels_active: number
    last_notification_at?: string | null
    failures_24h: number
}

interface DomainHealth {
    availability: AvailabilityHealth
    seo_business: SeoHealth
    infrastructure: InfraHealth
    alerting: AlertingHealth
}

interface HealthBreakdownDimension {
    score: number
    weight: number
    available?: boolean
}

interface SiteHealth {
    monitor_id: number
    site: string
    name: string
    score: number
    grade: string
    trend: 'up' | 'down' | 'flat'
    breakdown: {
        uptime: HealthBreakdownDimension
        seo: HealthBreakdownDimension
        perf: HealthBreakdownDimension
        ttfb: HealthBreakdownDimension
    }
    status?: 'up' | 'down' | 'pending'
    last_checked_at?: string | null
    down_since?: string | null
    uptime_30d?: number | null
    timeline?: Array<{ date: string; status: string; uptime: number | null; incidents: number }>
}

interface StatusSummary {
    level: 'ok' | 'warning' | 'critical'
    headline: string
    segments: Array<{ text: string; tone: 'ok' | 'warning' | 'critical' | 'neutral' }>
    monitors_total: number
    monitors_up: number
    monitors_down: number
    monitors_pending: number
    critical: number
    warnings: number
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
    // triageFeed, not triage: the shared middleware prop `triage` (nav badges)
    // must not be clobbered by this page prop.
    triageFeed?: TriageData | null
    statusSummary?: StatusSummary | null
    generatedAt?: string | null
    domainHealth?: DomainHealth | null
    healthPortfolio?: { sites: SiteHealth[]; computed_at: string } | null
    // SLA — kept from legacy payload; backend may still send
    slaTarget?: number
    slaCurrent?: number
    // Legacy metrics — kept for SLA bar backward compat only
    metrics?: { sla_target?: number; sla_current_month?: number } | null
}>()

// ─── Realtime ─────────────────────────────────────────────────────────────────

const flashKey = ref(0)
const isRefreshing = ref(false)

const triggerRefreshAnimation = () => {
    isRefreshing.value = true
    flashKey.value++
    setTimeout(() => { isRefreshing.value = false }, 600)
}

useRealtimeUpdates({
    onMonitorChecked: ['statusSummary', 'domainHealth', 'healthPortfolio', 'generatedAt'],
    onServerMetricReceived: ['domainHealth'],
    onIncidentCreated: ['triageFeed', 'statusSummary', 'domainHealth', 'generatedAt'],
    onIncidentResolved: ['triageFeed', 'statusSummary', 'domainHealth', 'generatedAt'],
    onInsightChanged: ['triageFeed', 'domainHealth', 'generatedAt'],
    onRefresh: triggerRefreshAnimation,
})

const { isLoading } = usePageLoading()

// ─── "updated Xs ago" clock ────────────────────────────────────────────────────
// Ticks every second so the header stays honest between partial reloads —
// a stale "just now" after 10 minutes would undermine the whole cockpit.

const nowTick = ref(Date.now())
let tickTimer: ReturnType<typeof setInterval> | undefined
onMounted(() => { tickTimer = setInterval(() => { nowTick.value = Date.now() }, 1000) })
onUnmounted(() => { if (tickTimer) clearInterval(tickTimer) })

const updatedAgoLabel = computed(() => {
    if (!props.generatedAt) return null
    const s = Math.max(0, Math.floor((nowTick.value - new Date(props.generatedAt).getTime()) / 1000))
    if (s < 5) return 'just now'
    if (s < 60) return `${s}s ago`
    const m = Math.floor(s / 60)
    if (m < 60) return `${m}m ago`
    return `${Math.floor(m / 60)}h ago`
})

const statusToneClass = computed(() => {
    switch (props.statusSummary?.level) {
        case 'critical': return 'text-[var(--color-danger)]'
        case 'warning': return 'text-[var(--color-warning)]'
        default: return 'text-[var(--color-text-primary)]'
    }
})

// ─── Derived ──────────────────────────────────────────────────────────────────

const triageItems = computed(() => props.triageFeed?.items ?? [])
const triageCounts = computed(() => props.triageFeed?.counts ?? null)
const triageBreakdown = computed(() => props.triageFeed?.breakdown ?? null)

const availData = computed(() => props.domainHealth?.availability ?? null)
const seoData = computed(() => props.domainHealth?.seo_business ?? null)
const infraData = computed(() => props.domainHealth?.infrastructure ?? null)
const alertingData = computed(() => props.domainHealth?.alerting ?? null)

const domainBadge = (key: string) => triageCounts.value?.domains?.[key] ?? 0

// SLA — support both new dedicated props and legacy metrics payload
const slaTarget = computed(() => props.slaTarget ?? props.metrics?.sla_target ?? 0)
const slaCurrent = computed(() => props.slaCurrent ?? props.metrics?.sla_current_month ?? 0)

// healthPortfolio typed to match HealthScorePortfolio prop
const portfolioHealth = computed(() => props.healthPortfolio
    ? { sites: props.healthPortfolio.sites as any[], computed_at: props.healthPortfolio.computed_at }
    : { sites: [], computed_at: '' }
)
</script>

<template>
    <Head title="Overview" />

    <div class="space-y-8">
        <!-- Header -->
        <div class="flex items-end justify-between">
            <PageHeader title="Overview" />
            <span v-if="isRefreshing" class="text-xs text-[var(--color-primary-400)] animate-pulse">Updating…</span>
        </div>

        <SkeletonDashboard v-if="isLoading" />

        <template v-else>
            <!-- Tier 0: one-sentence global state — the first thing read every morning -->
            <div v-if="statusSummary" class="flex items-center gap-2 flex-wrap">
                <span class="status-dot" :class="statusSummary.level === 'ok' ? 'online' : statusSummary.level === 'critical' ? 'offline' : ''" />
                <h2 data-testid="global-state" class="text-base font-semibold" :class="statusToneClass">{{ statusSummary.headline }}</h2>
                <span v-if="updatedAgoLabel" class="text-xs text-[var(--color-text-faint)] font-mono ml-1">updated {{ updatedAgoLabel }}</span>
            </div>

            <!-- Tier 1: Triage Banner -->
            <TriageBanner
                :items="triageItems"
                :counts="triageCounts"
                :breakdown="triageBreakdown"
                :availability="availData"
                :key="'triage-' + flashKey"
                @acknowledged="router.reload({ only: ['triageFeed', 'triage'] })"
            />

            <!-- SLA Bar -->
            <div v-if="slaTarget > 0" class="-mt-4">
                <SlaProgressBar :target="slaTarget" :current="slaCurrent" />
            </div>

            <!-- Tier 2: Domain Health Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" :class="{ 'opacity-80': isRefreshing }" :key="'domains-' + flashKey">
                <DomainHealthCard domain="availability" :data="availData" :badge="domainBadge('availability')" />
                <DomainHealthCard domain="seo_business" :data="seoData" :badge="domainBadge('seo_business')" />
                <DomainHealthCard domain="infrastructure" :data="infraData" :badge="domainBadge('infrastructure')" />
                <DomainHealthCard domain="alerting" :data="alertingData" :badge="domainBadge('alerting')" />
            </div>

            <!-- Tier 3: Portfolio Health -->
            <HealthScorePortfolio :health="portfolioHealth" />
        </template>
    </div>
</template>
