<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import NavIcon from '@/Components/Nav/NavIcon.vue'
import MetricGauge from '@/Components/MetricGauge.vue'

type Domain = 'availability' | 'seo_business' | 'infrastructure' | 'alerting'

interface WorstMonitor {
    id?: number
    name?: string
    status?: string
}

interface WorstSite {
    id?: number
    name?: string
    score?: number
}

interface AvailabilityData {
    monitors_total?: number
    monitors_up?: number
    incidents_open?: number
    uptime_30d?: number
    worst_monitor?: WorstMonitor | null
}

interface SeoData {
    health_avg?: number | null
    quick_wins?: number
    worst_site?: WorstSite | null
}

interface InfraServer {
    id?: number
    name?: string
    cpu?: number
    ram?: number
    disk?: number
    load_1?: number
}

interface InfraData {
    worst_server?: InfraServer | null
    servers_total?: number
    servers_silent?: number
}

interface AlertingData {
    channels_active?: number
    last_notification_at?: string | null
    failures_24h?: number
}

type DomainData = AvailabilityData | SeoData | InfraData | AlertingData

const props = defineProps<{
    domain: Domain
    data?: DomainData | null
    badge?: number
}>()

const domainConfig = computed(() => {
    const configs: Record<Domain, { label: string; icon: string; href: string }> = {
        availability: { label: 'Availability', icon: 'activity', href: '/monitors' },
        seo_business: { label: 'SEO & Business', icon: 'sites', href: '/sites' },
        infrastructure: { label: 'Infrastructure', icon: 'server', href: '/servers' },
        alerting: { label: 'Alerting', icon: 'bell-alert', href: '/channels' },
    }
    return configs[props.domain]
})

const badgeClass = computed(() => props.badge ? 'bg-[var(--color-warning)]/15 text-[var(--color-warning)] border-[var(--color-warning)]/30' : '')

const avail = computed(() => props.domain === 'availability' ? (props.data as AvailabilityData) : null)
const seo = computed(() => props.domain === 'seo_business' ? (props.data as SeoData) : null)
const infra = computed(() => props.domain === 'infrastructure' ? (props.data as InfraData) : null)
const alerting = computed(() => props.domain === 'alerting' ? (props.data as AlertingData) : null)

const timeAgo = (iso: string | null | undefined): string => {
    if (!iso) return 'Never'
    const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)
    if (m < 1) return 'Just now'
    if (m < 60) return `${m}m ago`
    if (m < 1440) return `${Math.floor(m / 60)}h ago`
    return `${Math.floor(m / 1440)}d ago`
}
</script>

<template>
    <Link :href="domainConfig.href" class="group block rounded-md bg-[var(--color-surface-0)] border border-[var(--color-border)] p-4 hover:border-[var(--color-border-hover)] hover:bg-[var(--color-surface-1)] transition-all">
        <!-- Card header -->
        <div class="flex items-center justify-between mb-2.5">
            <div class="flex items-center gap-2 text-[var(--color-text-secondary)] group-hover:text-[var(--color-text-primary)] transition-colors">
                <NavIcon :name="domainConfig.icon" />
                <span class="text-xs font-semibold uppercase tracking-wider">{{ domainConfig.label }}</span>
            </div>
            <span v-if="badge" class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded text-[10px] font-bold border" :class="badgeClass">{{ badge }}</span>
        </div>

        <!-- AVAILABILITY -->
        <template v-if="domain === 'availability' && avail">
            <div class="space-y-1.5">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-2xl font-bold text-[var(--color-text-primary)] tabular-nums">{{ avail.monitors_up ?? 0 }}</span>
                    <span class="text-[var(--color-text-muted)] text-sm">/ {{ avail.monitors_total ?? 0 }} up</span>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <span class="text-[var(--color-text-muted)]">
                        <span :class="(avail.incidents_open ?? 0) > 0 ? 'text-[var(--color-danger)] font-medium' : 'text-[var(--color-text-secondary)]'">
                            {{ avail.incidents_open ?? 0 }}
                        </span> incidents
                    </span>
                    <span class="text-[var(--color-text-muted)]">
                        <span class="text-[var(--color-text-secondary)] font-mono">{{ (avail.uptime_30d ?? 100).toFixed(1) }}%</span> 30d
                    </span>
                </div>
                <p v-if="avail.worst_monitor" class="flex items-center gap-1.5 text-xs text-[var(--color-danger)] truncate font-mono">
                    <span class="truncate">{{ avail.worst_monitor.name }}</span>
                    <span v-if="avail.worst_monitor.status === 'down'" class="flex-shrink-0 w-1.5 h-1.5 rounded-full bg-[var(--color-danger)]" />
                </p>
            </div>
        </template>

        <!-- SEO & BUSINESS -->
        <template v-else-if="domain === 'seo_business' && seo">
            <div class="space-y-1.5">
                <div class="flex items-baseline gap-1.5">
                    <span
                        class="text-2xl font-bold tabular-nums"
                        :class="(seo.health_avg ?? 0) >= 80 ? 'text-[var(--color-text-primary)]' : (seo.health_avg ?? 0) >= 60 ? 'text-[var(--color-warning)]' : 'text-[var(--color-danger)]'"
                    >
                        {{ Math.round(seo.health_avg ?? 0) }}
                    </span>
                    <span class="text-[var(--color-text-muted)] text-sm">/ 100</span>
                </div>
                <p v-if="(seo.quick_wins ?? 0) > 0" class="text-xs text-[var(--color-warning)]">{{ seo.quick_wins }} quick win{{ (seo.quick_wins ?? 0) > 1 ? 's' : '' }}</p>
                <p v-if="seo.worst_site" class="text-xs text-[var(--color-danger)] truncate font-mono">
                    <span>{{ seo.worst_site.name }}</span>
                    <span v-if="seo.worst_site.score != null" class="text-[var(--color-text-muted)]"> · {{ seo.worst_site.score }}/100</span>
                </p>
            </div>
        </template>

        <!-- INFRASTRUCTURE -->
        <template v-else-if="domain === 'infrastructure' && infra">
            <div class="space-y-1.5">
                <template v-if="infra.worst_server">
                    <p class="text-xs font-medium text-[var(--color-text-secondary)] truncate">{{ infra.worst_server.name }}</p>
                    <div class="space-y-1">
                        <MetricGauge label="CPU" :percent="infra.worst_server.cpu ?? 0" :warning="75" :critical="90" />
                        <MetricGauge label="RAM" :percent="infra.worst_server.ram ?? 0" :warning="80" :critical="90" />
                        <MetricGauge label="Disk" :percent="infra.worst_server.disk ?? 0" :warning="80" :critical="90" />
                    </div>
                </template>
                <p v-else class="text-sm text-[var(--color-text-muted)]">{{ infra.servers_total ?? 0 }} server{{ (infra.servers_total ?? 0) !== 1 ? 's' : '' }}</p>
                <p v-if="(infra.servers_silent ?? 0) > 0" class="text-xs text-[var(--color-warning)]">{{ infra.servers_silent }} silent</p>
            </div>
        </template>

        <!-- ALERTING -->
        <template v-else-if="domain === 'alerting' && alerting">
            <div class="space-y-1.5">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-2xl font-bold text-[var(--color-text-primary)] tabular-nums">{{ alerting.channels_active ?? 0 }}</span>
                    <span class="text-[var(--color-text-muted)] text-sm">active</span>
                </div>
                <p class="text-xs text-[var(--color-text-muted)]">Last notified <span class="text-[var(--color-text-secondary)]">{{ timeAgo(alerting.last_notification_at) }}</span></p>
                <p v-if="(alerting.failures_24h ?? 0) > 0" class="text-xs text-[var(--color-danger)]">{{ alerting.failures_24h }} failure{{ (alerting.failures_24h ?? 0) > 1 ? 's' : '' }} today</p>
            </div>
        </template>

        <!-- Fallback if no data yet -->
        <template v-else>
            <div class="h-12 flex items-center">
                <span class="text-xs text-[var(--color-text-faint)]">No data yet</span>
            </div>
        </template>
    </Link>
</template>
