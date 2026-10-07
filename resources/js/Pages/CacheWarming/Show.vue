<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import BackLink from '@/Components/BackLink.vue'
import GlassCard from '@/Components/GlassCard.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import WarmingHitRatioChart from '@/Components/WarmingHitRatioChart.vue'
import Tag from 'primevue/tag'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'

interface Stats24h {
    runs_completed: number
    runs_total: number
    total_urls: number
    hit_ratio: number | null
    avg_response_ms: number
    total_errors: number
}

interface LastSuccessfulRun {
    started_at: string
    urls_total: number
    hit_ratio: number
}

interface RecentRun {
    id: number
    urls_total: number
    urls_hit: number
    urls_miss: number
    urls_error: number
    hit_ratio: number
    avg_response_ms: number
    status: string
    error_message: string | null
    duration_seconds: number | null
    started_at: string
    completed_at: string | null
}

interface ChartDataPoint {
    date: string
    hit_ratio: number
    avg_ms: number
}

const props = defineProps<{
    warmSite: {
        id: number
        name: string
        domain: string
        mode: string
        mode_label: string
        frequency_minutes: number
        is_active: boolean
        last_warmed_at: string | null
        sitemap_url: string | null
        urls: string[] | null
        max_urls: number
        timeout_seconds: number
        monitor: { id: number; name: string; url: string } | null
    }
    stats24h: Stats24h
    lastSuccessfulRun: LastSuccessfulRun | null
    recentRuns: RecentRun[]
    chartData: ChartDataPoint[]
}>()

useRealtimeUpdates({
    onWarmRunProgress: ['recentRuns', 'chartData', 'warmSite'],
})

const showDeleteDialog = ref(false)
const deleteForm = useForm({})

const warmNow = () => router.post(route('warming.warm-now', props.warmSite.id))

const confirmDelete = () => {
    deleteForm.delete(route('warming.destroy', props.warmSite.id))
}

const frequencyLabel = (mins: number): string => {
    if (mins < 60) return `Every ${mins} min`
    if (mins === 60) return 'Every hour'
    if (mins < 1440) return `Every ${mins / 60} hours`
    return 'Every 24 hours'
}

const formatDate = (d: string) =>
    new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })

const formatDuration = (seconds: number | null): string => {
    if (seconds === null) return '-'
    if (seconds < 60) return `${seconds}s`
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
}

const runStatusSeverity = (status: string): 'success' | 'warn' | 'danger' | 'secondary' => {
    if (status === 'completed') return 'success'
    if (status === 'running') return 'warn'
    if (status === 'failed') return 'danger'
    return 'secondary'
}

const hitRatioClass = (ratio: number): string => {
    if (ratio >= 80) return 'text-emerald-400'
    if (ratio >= 50) return 'text-yellow-400'
    return 'text-red-400'
}

const relativeTime = (iso: string): string => {
    const diff = Date.now() - new Date(iso).getTime()
    const mins = Math.floor(diff / 60000)
    if (mins < 1) return 'just now'
    if (mins < 60) return `${mins}m ago`
    const hours = Math.floor(mins / 60)
    if (hours < 24) return `${hours}h ago`
    return `${Math.floor(hours / 24)}d ago`
}

const filteredRuns = computed(() => {
    const withUrls = props.recentRuns.filter(r => r.urls_total > 0)
    const failedEmpty = props.recentRuns.filter(r => r.urls_total === 0).slice(0, 3)
    return [...withUrls, ...failedEmpty]
        .sort((a, b) => new Date(b.started_at).getTime() - new Date(a.started_at).getTime())
        .slice(0, 10)
})
</script>

<template>
    <Head :title="`${warmSite.name} — Cache Warming`" />

    <div class="space-y-8">
        <BackLink :href="route('warming.index')" label="Back to Cache Warming" />

        <!-- Site header -->
        <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-3 flex-wrap">
                    <h1 class="text-2xl font-bold text-white tracking-tight">{{ warmSite.name }}</h1>
                    <Tag
                        :value="warmSite.mode_label"
                        :severity="warmSite.mode === 'sitemap' ? 'info' : 'secondary'"
                    />
                    <span
                        v-if="!warmSite.is_active"
                        class="px-2 py-0.5 rounded text-xs font-bold uppercase bg-zinc-800 text-zinc-400"
                    >
                        Paused
                    </span>
                </div>
                <div class="flex items-center gap-4 mt-2 text-sm text-zinc-500">
                    <span class="font-mono">{{ warmSite.domain }}</span>
                    <span>·</span>
                    <span>{{ frequencyLabel(warmSite.frequency_minutes) }}</span>
                    <span v-if="warmSite.last_warmed_at">
                        · Last run {{ formatDate(warmSite.last_warmed_at) }}
                    </span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-3 shrink-0">
                <button
                    @click="warmNow"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20 transition-colors"
                >
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Warm Now
                </button>
                <Link
                    :href="route('warming.edit', warmSite.id)"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-white/5 text-zinc-300 border border-white/10 hover:bg-white/10 transition-colors"
                >
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    Edit
                </Link>
                <button
                    @click="showDeleteDialog = true"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20 transition-colors"
                >
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                    </svg>
                    Delete
                </button>
            </div>
        </div>

        <!-- Linked Monitor -->
        <div v-if="warmSite.monitor" class="glass p-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-2 h-2 rounded-full bg-emerald-400 shrink-0"></div>
                <div>
                    <span class="text-sm text-zinc-400">Linked Monitor:</span>
                    <Link :href="route('monitors.show', warmSite.monitor.id)" class="text-emerald-400 hover:underline ml-2 text-sm font-medium">
                        {{ warmSite.monitor.name }}
                    </Link>
                    <span class="text-zinc-600 text-xs ml-2 font-mono">{{ warmSite.monitor.url }}</span>
                </div>
            </div>
        </div>

        <!-- Last successful warm badge -->
        <div v-if="lastSuccessfulRun" class="glass p-3 flex items-center gap-3 text-sm">
            <span class="text-zinc-400">Last successful warm:</span>
            <span class="text-white font-medium">{{ relativeTime(lastSuccessfulRun.started_at) }}</span>
            <span class="text-zinc-500">·</span>
            <span class="text-zinc-400">{{ lastSuccessfulRun.urls_total }} URLs</span>
            <span class="text-zinc-500">·</span>
            <span class="text-emerald-400">{{ Math.round(lastSuccessfulRun.hit_ratio * 100) }}% hit</span>
        </div>

        <!-- Stats cards (24h aggregated) -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <GlassCard>
                <div class="text-2xl font-bold" :class="stats24h.hit_ratio !== null ? hitRatioClass(stats24h.hit_ratio) : 'text-zinc-600'">
                    {{ stats24h.hit_ratio !== null ? `${stats24h.hit_ratio}%` : '—' }}
                </div>
                <div class="text-xs text-zinc-500 uppercase tracking-wider mt-1">Hit Ratio</div>
                <div class="text-xs text-zinc-600 mt-0.5">24h avg</div>
            </GlassCard>
            <GlassCard>
                <div class="text-2xl font-bold text-white">{{ stats24h.total_urls || '—' }}</div>
                <div class="text-xs text-zinc-500 uppercase tracking-wider mt-1">URLs Warmed</div>
                <div class="text-xs text-zinc-600 mt-0.5">24h total</div>
            </GlassCard>
            <GlassCard>
                <div class="text-2xl font-bold text-white">
                    {{ stats24h.avg_response_ms ? `${stats24h.avg_response_ms}ms` : '—' }}
                </div>
                <div class="text-xs text-zinc-500 uppercase tracking-wider mt-1">Avg Response</div>
                <div class="text-xs text-zinc-600 mt-0.5">24h avg</div>
            </GlassCard>
            <GlassCard>
                <div class="text-2xl font-bold" :class="stats24h.runs_total > 0 ? 'text-white' : 'text-zinc-600'">
                    {{ stats24h.runs_total > 0 ? `${stats24h.runs_completed}/${stats24h.runs_total}` : '—' }}
                </div>
                <div class="text-xs text-zinc-500 uppercase tracking-wider mt-1">Success Rate</div>
                <div class="text-xs text-zinc-600 mt-0.5">24h runs</div>
            </GlassCard>
        </div>

        <!-- Hit Ratio Chart -->
        <div class="glass p-4">
            <h3 class="text-sm font-medium text-zinc-400 mb-3">Hit Ratio Trend</h3>
            <WarmingHitRatioChart :chartData="chartData" />
        </div>

        <!-- Config detail (sitemap_url or urls list) -->
        <GlassCard title="Configuration">
            <div class="space-y-3 text-sm">
                <div class="flex gap-6 flex-wrap">
                    <div>
                        <span class="text-zinc-500 uppercase text-xs tracking-wider">Mode</span>
                        <p class="text-white mt-0.5">{{ warmSite.mode_label }}</p>
                    </div>
                    <div>
                        <span class="text-zinc-500 uppercase text-xs tracking-wider">Max URLs</span>
                        <p class="text-white mt-0.5">{{ warmSite.max_urls }}</p>
                    </div>
                    <div>
                        <span class="text-zinc-500 uppercase text-xs tracking-wider">Frequency</span>
                        <p class="text-white mt-0.5">{{ frequencyLabel(warmSite.frequency_minutes) }}</p>
                    </div>
                    <div>
                        <span class="text-zinc-500 uppercase text-xs tracking-wider">Timeout</span>
                        <p class="text-white mt-0.5">{{ warmSite.timeout_seconds }}s</p>
                    </div>
                </div>
                <div v-if="warmSite.sitemap_url">
                    <span class="text-zinc-500 uppercase text-xs tracking-wider">Sitemap URL</span>
                    <p class="text-white font-mono mt-0.5 text-xs break-all">{{ warmSite.sitemap_url }}</p>
                </div>
                <div v-if="warmSite.urls && warmSite.urls.length > 0">
                    <span class="text-zinc-500 uppercase text-xs tracking-wider">URLs ({{ warmSite.urls.length }})</span>
                    <div class="mt-1 max-h-36 overflow-y-auto space-y-0.5">
                        <p v-for="url in warmSite.urls" :key="url" class="text-white font-mono text-xs">{{ url }}</p>
                    </div>
                </div>
            </div>
        </GlassCard>

        <!-- Recent Runs table -->
        <GlassCard title="Recent Runs" :padding="0">
            <template #actions>
                <span class="text-xs text-zinc-500">Last {{ filteredRuns.length }} runs</span>
            </template>
            <div v-if="filteredRuns.length === 0" class="flex flex-col items-center justify-center py-12 text-center">
                <p class="text-zinc-500 text-sm">No runs yet. Click "Warm Now" to trigger the first run.</p>
            </div>
            <DataTable
                v-else
                :value="filteredRuns"
                class="w-full"
                :pt="{
                    root: { class: 'bg-transparent' },
                    thead: { class: 'border-b border-white/5' },
                    tbody: { class: 'divide-y divide-white/5' },
                }"
            >
                <Column header="Started" style="min-width: 140px">
                    <template #body="{ data }">
                        <Link
                            :href="route('warming.run-detail', { warming: warmSite.id, warmRun: data.id })"
                            class="text-emerald-400 hover:underline text-sm"
                        >
                            {{ formatDate(data.started_at) }}
                        </Link>
                    </template>
                </Column>
                <Column header="Duration" style="min-width: 90px">
                    <template #body="{ data }">
                        <span class="text-sm text-zinc-400 font-mono">{{ formatDuration(data.duration_seconds) }}</span>
                    </template>
                </Column>
                <Column header="Total" style="min-width: 70px">
                    <template #body="{ data }">
                        <span class="text-sm text-zinc-300">{{ data.urls_total }}</span>
                    </template>
                </Column>
                <Column header="Hit %" style="min-width: 80px">
                    <template #body="{ data }">
                        <span class="text-sm font-medium" :class="hitRatioClass(data.hit_ratio)">{{ data.hit_ratio }}%</span>
                    </template>
                </Column>
                <Column header="Miss" style="min-width: 70px">
                    <template #body="{ data }">
                        <span class="text-sm text-zinc-400">{{ data.urls_miss }}</span>
                    </template>
                </Column>
                <Column header="Errors" style="min-width: 70px">
                    <template #body="{ data }">
                        <span class="text-sm" :class="data.urls_error > 0 ? 'text-red-400' : 'text-zinc-600'">{{ data.urls_error }}</span>
                    </template>
                </Column>
                <Column header="Status" style="min-width: 100px">
                    <template #body="{ data }">
                        <Tag
                            :value="data.status"
                            :severity="runStatusSeverity(data.status)"
                            class="text-xs capitalize"
                        />
                    </template>
                </Column>
            </DataTable>
        </GlassCard>
    </div>

    <!-- Delete confirmation -->
    <ConfirmDialog
        v-model:show="showDeleteDialog"
        title="Delete Warm Site"
        :message="`Are you sure you want to delete '${warmSite.name}'? All run history will be permanently removed.`"
        confirm-label="Delete"
        variant="danger"
        @confirm="confirmDelete"
    />
</template>
