<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import SiteScopeBanner from '@/Components/SiteScopeBanner.vue'
import MetricGauge from '@/Components/MetricGauge.vue'
import Sparkline from '@/Components/Sparkline.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import type { SiteScope } from '@/Types/page'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

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

interface SparkPoint {
    t: string
    cpu: number
    ram: number
    disk: number
}

interface Server {
    id: number
    name: string
    is_active: boolean
    has_monitoring: boolean
    latest: LatestMetric | null
    sparkline: SparkPoint[]
    sites: string[]
}

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

const props = defineProps<{
    servers: Server[]
}>()

// ---------------------------------------------------------------------------
// Delete
// ---------------------------------------------------------------------------

const deleteTarget = ref<Server | null>(null)
const showDeleteDialog = ref(false)

const confirmDelete = (server: Server) => {
    deleteTarget.value = server
    showDeleteDialog.value = true
}

const handleDelete = () => {
    if (!deleteTarget.value) return
    router.delete(route('servers.destroy', deleteTarget.value.id))
    deleteTarget.value = null
}

// ---------------------------------------------------------------------------
// Live updates
// ---------------------------------------------------------------------------

useRealtimeUpdates({ onServerMetricReceived: ['servers'] })

// ---------------------------------------------------------------------------
// Thresholds
// These mirror config/monitoring.php server_health thresholds — keep in sync.
// ---------------------------------------------------------------------------
const CPU_WARN = 90
const CPU_CRIT = 97
const RAM_WARN = 88
const RAM_CRIT = 95
const DISK_WARN = 85
const DISK_CRIT = 92

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function mbToGb(mb: number): string {
    return (mb / 1024).toFixed(1)
}

function ramDetail(m: LatestMetric): string {
    return `${mbToGb(m.ram_used_mb)}/${mbToGb(m.ram_total_mb)} GB`
}

function diskDetail(m: LatestMetric): string {
    return `${m.disk_used_gb}/${m.disk_total_gb} GB`
}

function sparkValues(spark: SparkPoint[], metric: 'cpu' | 'ram' | 'disk'): number[] {
    return spark.map((p) => p[metric])
}

function sparkColor(metric: 'cpu' | 'ram' | 'disk'): string {
    return { cpu: '#3b82f6', ram: '#a855f7', disk: '#f59e0b' }[metric]
}

function relativeTime(iso: string | null): string {
    if (!iso) return ''
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
    if (diff < 60) return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    return `${Math.floor(diff / 3600)}h ago`
}

const page = usePage()
const siteScope = computed((): SiteScope | null => (page.props as any).siteScope ?? null)
const isSiteScoped = computed(() => siteScope.value?.mode === 'site')
</script>

<template>
    <Head title="Servers" />

    <div class="space-y-8">
        <!-- Site scope banner — shown when filter is active -->
        <SiteScopeBanner />

        <PageHeader
            title="Servers"
            description="Live resource health of your Dokploy servers."
        >
            <template #actions>
                <Link :href="route('servers.create')">
                    <button class="btn-primary flex items-center gap-2 px-4 py-2">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        Add Server
                    </button>
                </Link>
            </template>
        </PageHeader>

        <!-- Empty state: site-scoped vs global -->
        <template v-if="servers.length === 0">
            <EmptyState
                v-if="isSiteScoped"
                icon="server"
                :title="`No servers linked to ${siteScope?.site?.name}`"
                description="No servers are associated with this site."
            >
                <template #action>
                    <Link :href="route('servers.index') + '?site=all'">
                        <button class="px-4 py-2 rounded-lg text-sm font-medium bg-white/5 hover:bg-white/10 text-white border border-white/10 transition-colors">
                            View all servers
                        </button>
                    </Link>
                </template>
            </EmptyState>
            <EmptyState
                v-else
                icon="server"
                title="No servers yet"
                description="Add a server, generate a token from its detail page, then install the metrics agent on the host."
            >
                <template #action>
                    <Link :href="route('servers.create')">
                        <button class="btn-primary flex items-center gap-2 px-4 py-2">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                            </svg>
                            Add Server
                        </button>
                    </Link>
                </template>
            </EmptyState>
        </template>

        <!-- Server cards -->
        <div v-if="servers.length > 0" class="grid gap-4 sm:grid-cols-1 lg:grid-cols-2 xl:grid-cols-2">
            <div
                v-for="server in servers"
                :key="server.id"
                class="group flex flex-col gap-5 p-5 border border-white/5 rounded-xl bg-white/[0.02] hover:bg-white/[0.03] transition-colors"
            >
                <!-- Card header -->
                <div class="flex items-start justify-between gap-3">
                    <Link
                        :href="route('servers.show', server.id)"
                        class="flex items-center gap-3 min-w-0 flex-1"
                    >
                        <!-- Active dot -->
                        <span
                            v-if="server.is_active"
                            class="shrink-0 block w-2 h-2 rounded-full bg-emerald-500"
                        />
                        <span
                            v-else
                            class="shrink-0 block w-2 h-2 rounded-full border-2 border-zinc-600 bg-transparent"
                        />
                        <h2 class="text-white font-semibold tracking-tight truncate hover:text-emerald-400 transition-colors">{{ server.name }}</h2>
                    </Link>

                    <div class="flex items-center gap-2 shrink-0">
                        <!-- Last update timestamp -->
                        <span
                            v-if="server.latest?.captured_at"
                            class="text-[10px] text-zinc-600 font-mono"
                        >
                            {{ relativeTime(server.latest.captured_at) }}
                        </span>
                        <!-- Monitoring not configured badge -->
                        <span
                            v-if="!server.has_monitoring"
                            class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border bg-amber-500/10 text-amber-400 border-amber-500/20"
                        >
                            Not configured
                        </span>
                        <!-- Card actions (hover) -->
                        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <Link
                                :href="route('servers.show', server.id)"
                                class="p-1.5 rounded-lg text-zinc-500 hover:text-white hover:bg-white/5 transition-colors"
                                :aria-label="`Details for ${server.name}`"
                            >
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
                                </svg>
                            </Link>
                            <Link
                                :href="route('servers.edit', server.id)"
                                class="p-1.5 rounded-lg text-zinc-500 hover:text-white hover:bg-white/5 transition-colors"
                                :aria-label="`Edit ${server.name}`"
                            >
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                </svg>
                            </Link>
                            <button
                                @click="confirmDelete(server)"
                                class="p-1.5 rounded-lg text-zinc-500 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                                :aria-label="`Delete ${server.name}`"
                            >
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                    <path d="M10 11v6" /><path d="M14 11v6" />
                                    <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Monitoring not configured notice -->
                <div
                    v-if="!server.has_monitoring"
                    class="rounded-lg border border-amber-500/20 bg-amber-500/5 p-4 text-sm text-amber-400/80"
                >
                    No metrics agent configured.
                    <Link :href="route('servers.show', server.id)" class="underline text-amber-300 hover:text-amber-200 transition-colors ml-1">
                        Generate a token
                    </Link>
                    from the server detail page, then install the agent.
                </div>

                <!-- Waiting for first metrics -->
                <div
                    v-else-if="server.has_monitoring && server.latest === null"
                    class="rounded-lg border border-white/5 bg-white/[0.02] p-4 text-sm text-zinc-500 text-center"
                >
                    Waiting for first metrics&hellip;
                </div>

                <!-- Metric gauges + sparklines -->
                <template v-else-if="server.latest">
                    <div class="space-y-4">
                        <!-- CPU -->
                        <div class="space-y-2">
                            <MetricGauge
                                label="CPU"
                                :percent="server.latest.cpu"
                                :warning="CPU_WARN"
                                :critical="CPU_CRIT"
                            />
                            <Sparkline
                                :points="sparkValues(server.sparkline, 'cpu')"
                                :color="sparkColor('cpu')"
                            />
                        </div>

                        <!-- RAM -->
                        <div class="space-y-2">
                            <MetricGauge
                                label="RAM"
                                :percent="server.latest.ram"
                                :warning="RAM_WARN"
                                :critical="RAM_CRIT"
                                :detail="ramDetail(server.latest)"
                            />
                            <Sparkline
                                :points="sparkValues(server.sparkline, 'ram')"
                                :color="sparkColor('ram')"
                            />
                        </div>

                        <!-- Disk -->
                        <div class="space-y-2">
                            <MetricGauge
                                label="Disk"
                                :percent="server.latest.disk"
                                :warning="DISK_WARN"
                                :critical="DISK_CRIT"
                                :detail="diskDetail(server.latest)"
                            />
                            <Sparkline
                                :points="sparkValues(server.sparkline, 'disk')"
                                :color="sparkColor('disk')"
                            />
                        </div>

                        <!-- Load average (1/5/15 min) -->
                        <div
                            v-if="server.latest.load_avg_1 !== null"
                            class="flex items-center justify-between pt-1 text-xs"
                        >
                            <span class="text-zinc-500 uppercase tracking-wider text-[10px] font-semibold">Load avg</span>
                            <span class="font-mono text-zinc-300">
                                {{ server.latest.load_avg_1?.toFixed(2) }}
                                <span class="text-zinc-600">·</span>
                                {{ server.latest.load_avg_5?.toFixed(2) }}
                                <span class="text-zinc-600">·</span>
                                {{ server.latest.load_avg_15?.toFixed(2) }}
                            </span>
                        </div>
                    </div>
                </template>

                <!-- Divider -->
                <div class="border-t border-white/5" />

                <!-- Hosted sites -->
                <div>
                    <span class="text-[10px] font-semibold text-zinc-600 uppercase tracking-wider">
                        Hosted sites
                    </span>
                    <div v-if="server.sites.length > 0" class="flex flex-wrap gap-1.5 mt-2">
                        <span
                            v-for="domain in server.sites"
                            :key="domain"
                            class="px-2 py-0.5 rounded text-[10px] font-mono border border-white/5 bg-white/5 text-zinc-400"
                        >
                            {{ domain }}
                        </span>
                    </div>
                    <p v-else class="text-xs text-zinc-600 mt-1.5">No sites linked.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete confirmation -->
    <ConfirmDialog
        v-model:show="showDeleteDialog"
        title="Delete Server"
        :message="`Are you sure you want to delete '${deleteTarget?.name}'? All metrics history will be deleted.`"
        confirm-label="Delete Server"
        variant="danger"
        @confirm="handleDelete"
        @cancel="deleteTarget = null"
    />
</template>
