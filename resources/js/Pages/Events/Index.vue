<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import GlassCard from '@/Components/GlassCard.vue'
import Select from 'primevue/select'
import { usePersistentFilters } from '@/Composables/usePersistentFilters'

interface IngestSource {
    id: number
    name: string
}

interface IngestEventItem {
    id: number
    source_id: number
    source_name: string
    type: string
    level: string
    message: string
    context: Record<string, unknown> | null
    occurred_at: string
}

interface LevelOption {
    value: string
    label: string
}

interface TypeOption {
    value: string
    label: string
}

const props = defineProps<{
    events: {
        data: IngestEventItem[]
        next_cursor: string | null
        prev_cursor: string | null
        next_page_url: string | null
        prev_page_url: string | null
    }
    sources: IngestSource[]
    alertCount: number
    levels: LevelOption[]
    types: TypeOption[]
    filters: Record<string, string>
}>()

interface EventFilters {
    source_id: string
    level: string
    type: string
    from: string
    to: string
}

const { filters, clearFilters } = usePersistentFilters<EventFilters>(
    'ingest-events',
    {
        source_id: props.filters.source_id || '',
        level: props.filters.level || '',
        type: props.filters.type || '',
        from: props.filters.from || '',
        to: props.filters.to || '',
    },
    'events.index',
    undefined,
    // Filter changes only need the event list + counters, not the full page.
    ['events', 'alertCount', 'filters']
)

// Real-time updates via Reverb
const page = usePage()
const Echo = (window as any).Echo
const teamId = (page.props as any).auth?.team?.id

let echoChannel: any = null

// One reload per event turns a burst (a chatty source, serial job failures)
// into a request storm and constant re-renders. Throttle trailing-edge: the
// first event of a quiet period refreshes promptly, then at most one refresh
// per window regardless of how many land.
const RELOAD_THROTTLE_MS = 5000
let reloadTimer: ReturnType<typeof setTimeout> | null = null
let lastReloadAt = 0

const scheduleReload = () => {
    if (reloadTimer) return

    const wait = Math.max(0, lastReloadAt + RELOAD_THROTTLE_MS - Date.now())

    reloadTimer = setTimeout(() => {
        reloadTimer = null
        lastReloadAt = Date.now()
        router.reload({ only: ['events', 'alertCount'], preserveScroll: true })
    }, wait)
}

onMounted(() => {
    if (!Echo || !teamId) return
    echoChannel = Echo.private(`team.${teamId}`)
        .listen('.ingest.event.received', scheduleReload)
})

onUnmounted(() => {
    if (reloadTimer) clearTimeout(reloadTimer)
    if (echoChannel) {
        // Detach only this page's listener — the team channel is shared with
        // useRealtimeUpdates subscribers; leaving it would drop theirs too.
        echoChannel.stopListening('.ingest.event.received')
    }
})

// Expanded context rows
const expandedEvents = ref<Set<number>>(new Set())

const toggleExpand = (id: number) => {
    if (expandedEvents.value.has(id)) {
        expandedEvents.value.delete(id)
    } else {
        expandedEvents.value.add(id)
    }
}

// Level styles
const levelConfig: Record<string, { badge: string; dot: string }> = {
    debug: { badge: 'bg-zinc-500/20 text-zinc-400', dot: 'bg-zinc-500' },
    info: { badge: 'bg-blue-500/20 text-blue-400', dot: 'bg-blue-500' },
    warning: { badge: 'bg-yellow-500/20 text-yellow-400', dot: 'bg-yellow-500' },
    error: { badge: 'bg-red-500/20 text-red-400', dot: 'bg-red-500' },
    critical: { badge: 'bg-red-900/30 text-red-300 border border-red-800/50', dot: 'bg-red-700' },
    emergency: { badge: 'bg-red-950/40 text-red-200 border border-red-700/60', dot: 'bg-red-900' },
}

const sourceOptions = computed(() =>
    props.sources.map(s => ({ label: s.name, value: String(s.id) }))
)

const formatDate = (iso: string) => {
    return new Date(iso).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    })
}
</script>

<template>
    <Head title="Events" />

    <div class="space-y-6">
        <div class="flex items-end justify-between gap-4">
            <PageHeader title="Events" description="Application logs, job failures, and health checks.">
            </PageHeader>
            <div v-if="alertCount > 0" class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-red-500/10 border border-red-500/20">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                </span>
                <span class="text-xs font-medium text-red-400">{{ alertCount }} critical in 24h</span>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-3 pb-6 border-b border-white/5">
            <Select
                v-model="filters.source_id"
                :options="sourceOptions"
                optionLabel="label"
                optionValue="value"
                placeholder="Source"
                showClear
                class="w-44 !bg-transparent !border-white/10"
            />
            <Select
                v-model="filters.level"
                :options="levels"
                optionLabel="label"
                optionValue="value"
                placeholder="Level"
                showClear
                class="w-36 !bg-transparent !border-white/10"
            />
            <Select
                v-model="filters.type"
                :options="types"
                optionLabel="label"
                optionValue="value"
                placeholder="Type"
                showClear
                class="w-40 !bg-transparent !border-white/10"
            />
            <div class="h-4 w-px bg-white/10 mx-2 hidden md:block"></div>
            <input
                v-model="filters.from"
                type="date"
                class="bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-zinc-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none"
            />
            <span class="text-zinc-600">-</span>
            <input
                v-model="filters.to"
                type="date"
                class="bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-zinc-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none"
            />
        </div>

        <!-- Events List -->
        <div class="space-y-1">
            <div
                v-for="event in events.data"
                :key="event.id"
                class="group rounded-lg border border-white/5 hover:border-white/10 transition-colors"
                :class="{
                    'border-red-800/30 bg-red-950/10': event.level === 'critical' || event.level === 'emergency',
                    'bg-transparent': event.level !== 'critical' && event.level !== 'emergency',
                }"
            >
                <div
                    class="flex items-start gap-3 px-4 py-3 cursor-pointer"
                    @click="toggleExpand(event.id)"
                >
                    <!-- Level dot -->
                    <div class="shrink-0 mt-1.5">
                        <div
                            v-if="event.level === 'critical' || event.level === 'emergency'"
                            class="relative flex h-2.5 w-2.5"
                        >
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" :class="levelConfig[event.level]?.dot || 'bg-zinc-500'"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5" :class="levelConfig[event.level]?.dot || 'bg-zinc-500'"></span>
                        </div>
                        <div v-else class="h-2 w-2 rounded-full mt-0.5" :class="levelConfig[event.level]?.dot || 'bg-zinc-500'"></div>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <span :class="['px-2 py-0.5 rounded text-xs font-medium uppercase tracking-wide', levelConfig[event.level]?.badge || 'bg-zinc-500/20 text-zinc-400']">
                                {{ event.level }}
                            </span>
                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-white/5 text-zinc-500">
                                {{ event.type.replace('_', ' ') }}
                            </span>
                            <span class="text-xs text-zinc-600 font-medium">{{ event.source_name }}</span>
                        </div>
                        <p class="text-sm text-zinc-300 truncate">{{ event.message }}</p>
                    </div>

                    <!-- Time + expand icon -->
                    <div class="shrink-0 flex items-center gap-3">
                        <span class="text-xs text-zinc-500 font-mono whitespace-nowrap">{{ formatDate(event.occurred_at) }}</span>
                        <svg
                            :class="['w-3 h-3 text-zinc-600 transition-transform', expandedEvents.has(event.id) ? 'rotate-180' : '']"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="6 9 12 15 18 9" />
                        </svg>
                    </div>
                </div>

                <!-- Expanded context -->
                <div v-if="expandedEvents.has(event.id)" class="px-4 pb-4 border-t border-white/5 mt-0">
                    <div class="mt-3 space-y-2">
                        <p class="text-sm text-zinc-200 break-words">{{ event.message }}</p>
                        <div v-if="event.context" class="mt-2">
                            <p class="text-xs text-zinc-500 mb-1">Context</p>
                            <pre class="text-xs text-zinc-300 bg-black/30 p-3 rounded-lg overflow-x-auto font-mono whitespace-pre-wrap break-all">{{ JSON.stringify(event.context, null, 2) }}</pre>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="events.data.length === 0" class="py-12 text-center">
                <p class="text-zinc-500">No events found matching current filters.</p>
            </div>
        </div>

        <!-- Cursor Pagination -->
        <div v-if="events.next_page_url || events.prev_page_url" class="flex justify-between items-center pt-4">
            <a
                v-if="events.prev_page_url"
                :href="events.prev_page_url"
                class="px-4 py-2 text-sm text-zinc-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg transition-colors"
            >
                &larr; Newer
            </a>
            <span v-else class="px-4 py-2 text-sm text-zinc-700">Newest</span>

            <a
                v-if="events.next_page_url"
                :href="events.next_page_url"
                class="px-4 py-2 text-sm text-zinc-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg transition-colors"
            >
                Older &rarr;
            </a>
            <span v-else class="px-4 py-2 text-sm text-zinc-700">Oldest</span>
        </div>
    </div>
</template>
