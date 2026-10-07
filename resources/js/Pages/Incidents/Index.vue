<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SkeletonIncidentList from '@/Components/SkeletonIncidentList.vue'
import SiteScopeBanner from '@/Components/SiteScopeBanner.vue'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import type { SiteScope } from '@/Types/page'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import { usePersistentFilters } from '@/Composables/usePersistentFilters'
import { usePageLoading } from '@/Composables/usePageLoading'
import Tag from 'primevue/tag'
import SeverityBadge from '@/Components/SeverityBadge.vue'

useRealtimeUpdates({
    onMonitorChecked: ['incidents', 'activeCount'],
})
import Select from 'primevue/select'

const { isLoading } = usePageLoading()

const props = defineProps<{
    incidents: any
    activeCount: number
    monitors: Array<{ id: number; name: string }>
    causes: Array<{ value: string; label: string }>
    filters: Record<string, string>
}>()

interface IncidentFilters {
    status: string
    cause: string
    monitor_id: string
    from: string
    to: string
    sort: string
    dir: string
}

const { filters, clearFilters } = usePersistentFilters<IncidentFilters>(
    'incidents',
    {
        status: props.filters.status || '',
        cause: props.filters.cause || '',
        monitor_id: props.filters.monitor_id || '',
        from: props.filters.from || '',
        to: props.filters.to || '',
        sort: props.filters.sort || 'started_at',
        dir: props.filters.dir || 'desc',
    },
    'incidents.index',
    undefined,
    // Filter changes only need the incident list + counter, not the full page.
    ['incidents', 'activeCount', 'filters']
)

const toggleSort = (column: string) => {
    if (filters.value.sort === column) {
        filters.value.dir = filters.value.dir === 'asc' ? 'desc' : 'asc'
    } else {
        filters.value.sort = column
        filters.value.dir = 'desc'
    }
}

const statusOptions = [
    { label: 'Active', value: 'active' },
    { label: 'Resolved', value: 'resolved' },
]

const causeOptions = computed(() => props.causes)

const monitorOptions = computed(() =>
    props.monitors.map(m => ({ label: m.name, value: String(m.id) }))
)

// Ticks so an ongoing incident's elapsed time stays honest without a reload.
const now = ref(Date.now())
let nowTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
    nowTimer = setInterval(() => { now.value = Date.now() }, 1000)
})

onUnmounted(() => {
    if (nowTimer) clearInterval(nowTimer)
})

const humanizeSeconds = (seconds: number) => {
    if (seconds < 60) return `${seconds}s`
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
    const hours = Math.floor(seconds / 3600)
    const mins = Math.floor((seconds % 3600) / 60)
    return `${hours}h ${mins}m`
}

// An unresolved incident used to render "-", hiding the number the operator
// most wants while it is happening: how long it has been down.
const formatDuration = (startedAt: string, resolvedAt: string | null) => {
    const end = resolvedAt ? new Date(resolvedAt).getTime() : now.value
    const seconds = Math.max(0, Math.floor((end - new Date(startedAt).getTime()) / 1000))

    return humanizeSeconds(seconds)
}

const formatDate = (date: string) => {
    return new Date(date).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false })
}

const exportUrl = (format: string) => {
    const params = new URLSearchParams()
    params.set('format', format)
    Object.entries(filters.value).forEach(([k, v]) => { if (v && k !== 'sort' && k !== 'dir') params.set(k, v) })
    return route('incidents.export') + '?' + params.toString()
}

const typeLabels: Record<string, string> = {
    http: 'HTTP',
    ping: 'Ping',
    port: 'Port',
    dns: 'DNS',
}

const page = usePage()
const siteScope = computed((): SiteScope | null => (page.props as any).siteScope ?? null)
const isSiteScoped = computed(() => siteScope.value?.mode === 'site')
</script>

<template>
    <Head title="Incidents" />

    <SkeletonIncidentList v-if="isLoading" />

    <div v-else class="space-y-8">
        <!-- Site scope banner — shown when filter is active -->
        <SiteScopeBanner />

        <PageHeader title="Incidents" description="History of downtime and alerts.">
            <template #actions>
                <a :href="exportUrl('csv')" class="px-3 py-2 rounded-lg text-xs font-medium bg-white/5 hover:bg-white/10 text-white transition-colors border border-white/5">
                    Export CSV
                </a>
            </template>
        </PageHeader>

        <!-- Linear Filters -->
        <div class="flex flex-wrap items-center gap-3 pb-6 border-b border-white/5">
            <Select v-model="filters.status" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" showClear class="w-32 !bg-transparent !border-white/10" />
            <Select v-model="filters.cause" :options="causeOptions" optionLabel="label" optionValue="value" placeholder="Cause" showClear class="w-40 !bg-transparent !border-white/10" />
            <Select v-model="filters.monitor_id" :options="monitorOptions" optionLabel="label" optionValue="value" placeholder="Monitor" showClear class="w-48 !bg-transparent !border-white/10" />

            <div class="h-4 w-px bg-white/10 mx-2 hidden md:block"></div>

            <input v-model="filters.from" type="date" class="bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-zinc-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none transition-colors" placeholder="From" />
            <span class="text-zinc-600">-</span>
            <input v-model="filters.to" type="date" class="bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-zinc-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none transition-colors" placeholder="To" />
        </div>

        <!-- Linear List Header -->
        <div class="hidden md:grid grid-cols-12 gap-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider px-4">
            <div class="col-span-1">Status</div>
            <div @click="toggleSort('monitor_name')" @keydown.enter="toggleSort('monitor_name')" @keydown.space.prevent="toggleSort('monitor_name')" tabindex="0" role="columnheader" :aria-sort="filters.sort === 'monitor_name' ? (filters.dir === 'asc' ? 'ascending' : 'descending') : undefined" class="col-span-3 flex items-center gap-1 cursor-pointer select-none focus:outline-none focus:text-white" :class="filters.sort === 'monitor_name' ? 'text-white' : 'hover:text-zinc-300'">
                Monitor
                <span v-if="filters.sort === 'monitor_name'">{{ filters.dir === 'asc' ? '↑' : '↓' }}</span>
            </div>
            <div @click="toggleSort('cause')" @keydown.enter="toggleSort('cause')" @keydown.space.prevent="toggleSort('cause')" tabindex="0" role="columnheader" :aria-sort="filters.sort === 'cause' ? (filters.dir === 'asc' ? 'ascending' : 'descending') : undefined" class="col-span-2 flex items-center gap-1 cursor-pointer select-none focus:outline-none focus:text-white" :class="filters.sort === 'cause' ? 'text-white' : 'hover:text-zinc-300'">
                Cause
                <span v-if="filters.sort === 'cause'">{{ filters.dir === 'asc' ? '↑' : '↓' }}</span>
            </div>
            <div class="col-span-2">Severity</div>
            <div @click="toggleSort('started_at')" @keydown.enter="toggleSort('started_at')" @keydown.space.prevent="toggleSort('started_at')" tabindex="0" role="columnheader" :aria-sort="filters.sort === 'started_at' ? (filters.dir === 'asc' ? 'ascending' : 'descending') : undefined" class="col-span-2 flex items-center gap-1 cursor-pointer select-none focus:outline-none focus:text-white" :class="filters.sort === 'started_at' ? 'text-white' : 'hover:text-zinc-300'">
                Started
                <span v-if="filters.sort === 'started_at'">{{ filters.dir === 'asc' ? '↑' : '↓' }}</span>
            </div>
            <div @click="toggleSort('duration')" @keydown.enter="toggleSort('duration')" @keydown.space.prevent="toggleSort('duration')" tabindex="0" role="columnheader" :aria-sort="filters.sort === 'duration' ? (filters.dir === 'asc' ? 'ascending' : 'descending') : undefined" class="col-span-2 flex items-center justify-end gap-1 cursor-pointer select-none focus:outline-none focus:text-white" :class="filters.sort === 'duration' ? 'text-white' : 'hover:text-zinc-300'">
                Duration
                <span v-if="filters.sort === 'duration'">{{ filters.dir === 'asc' ? '↑' : '↓' }}</span>
            </div>
        </div>

        <!-- List Items -->
        <div class="space-y-1">
             <div v-for="incident in incidents.data" :key="incident.id" class="group grid grid-cols-1 md:grid-cols-12 gap-4 items-center py-3 px-4 rounded-lg bg-transparent hover:bg-white/[0.02] transition-colors border-b border-white/5 last:border-0">

                <!-- Status: text label at every breakpoint, colour is a reinforcement, not the only signal -->
                <div class="col-span-1 flex items-center gap-2">
                     <div v-if="!incident.resolved_at" class="relative flex h-2.5 w-2.5 flex-shrink-0">
                          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                          <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                    </div>
                    <div v-else class="h-2.5 w-2.5 rounded-full bg-zinc-600 flex-shrink-0"></div>
                    <span class="text-sm font-medium" :class="incident.resolved_at ? 'text-zinc-400' : 'text-red-400'">{{ incident.resolved_at ? 'Resolved' : 'Active' }}</span>
                </div>

                <!-- Monitor -->
                <div class="col-span-3 min-w-0">
                    <Link :href="route('monitors.show', incident.monitor_id)" class="block text-sm font-medium text-white hover:text-emerald-400 transition-colors truncate">
                        {{ incident.monitor_name }}
                    </Link>
                    <span class="text-[10px] text-zinc-600 font-mono">{{ typeLabels[incident.monitor_type] || incident.monitor_type }}</span>
                </div>

                <!-- Cause -->
                <div class="col-span-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-white/5 text-zinc-400 border border-white/5">
                        {{ incident.cause_label ?? incident.cause?.replace(/_/g, ' ') }}
                    </span>
                    <span v-if="incident.cause === 'functional' && incident.functional_check_name" class="block text-[10px] text-zinc-600 font-mono mt-0.5 truncate">
                        {{ incident.functional_check_name }}
                    </span>
                </div>

                <!-- Severity -->
                <div class="col-span-2">
                    <SeverityBadge v-if="incident.severity" :severity="incident.severity" />
                    <span v-else class="text-xs text-zinc-600">-</span>
                </div>

                <!-- Time -->
                <div class="col-span-2 font-mono text-sm text-zinc-400">
                    {{ formatDate(incident.started_at) }}
                </div>

                <!-- Duration + Fix action -->
                <div class="col-span-2 flex items-center justify-end gap-2">
                    <span
                        class="font-mono text-sm"
                        :class="incident.resolved_at ? 'text-zinc-500' : 'text-red-400'"
                        :title="incident.resolved_at ? 'Total downtime' : 'Still down — elapsed so far'"
                    >
                        {{ formatDuration(incident.started_at, incident.resolved_at) }}
                    </span>
                    <Link
                        :href="route('incidents.fix', incident.id)"
                        class="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity flex items-center justify-center w-7 h-7 rounded-lg bg-white/[0.04] hover:bg-emerald-500/15 border border-white/8 hover:border-emerald-500/20 text-zinc-500 hover:text-emerald-400 focus:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500/30 flex-shrink-0"
                        title="Fix with Claude"
                        :aria-label="`Fix with Claude: incident on ${incident.monitor_name}`"
                    >
                        <!-- Sparkle / wand icon -->
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 4V2"/>
                            <path d="M15 16v-2"/>
                            <path d="M8 9h2"/>
                            <path d="M20 9h2"/>
                            <path d="M17.8 11.8 19 13"/>
                            <path d="M15 9h.01"/>
                            <path d="M17.8 6.2 19 5"/>
                            <path d="m3 21 9-9"/>
                            <path d="M12.2 6.2 11 5"/>
                        </svg>
                    </Link>
                </div>
            </div>

             <!-- Empty State -->
            <div v-if="incidents.data.length === 0" class="py-12 text-center">
                <template v-if="isSiteScoped">
                    <p class="text-zinc-400 font-medium">No incidents for {{ siteScope?.site?.name }}</p>
                    <p class="text-zinc-600 text-sm mt-1">No incidents are linked to monitors scoped to this site.</p>
                    <Link :href="route('incidents.index') + '?site=all'" class="inline-block mt-4 text-sm text-emerald-500 hover:text-emerald-400 transition-colors">View all incidents</Link>
                </template>
                <template v-else>
                    <p class="text-zinc-500">No incidents found matching current filters.</p>
                </template>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="incidents.last_page > 1" class="flex justify-center gap-2 pt-8">
             <template v-for="link in incidents.links" :key="link.label">
                <Link v-if="link.url" :href="link.url"
                    :class="['px-3 py-1.5 text-xs font-medium rounded-md transition-colors font-mono',
                        link.active ? 'bg-emerald-500/10 text-emerald-500 border border-emerald-500/20' : 'text-zinc-500 hover:text-zinc-300 hover:bg-white/5']"
                    v-html="link.label" />
                <span v-else class="px-3 py-1.5 text-xs font-medium text-zinc-700 font-mono" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
