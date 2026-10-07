<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SkeletonMonitorList from '@/Components/SkeletonMonitorList.vue'
import SiteScopeBanner from '@/Components/SiteScopeBanner.vue'
import { computed, reactive, ref, watch } from 'vue'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import { usePageLoading } from '@/Composables/usePageLoading'
import { useSimplePersistentFilter } from '@/Composables/usePersistentFilters'
import DataView from 'primevue/dataview'
import Dialog from 'primevue/dialog'
import type { SiteScope } from '@/Types/page'

useRealtimeUpdates({
    onMonitorChecked: ['monitors'],
})
import SelectButton from 'primevue/selectbutton'
import Button from 'primevue/button'
import Icon from '@/Components/Icon.vue'

interface LatestCheck {
    status: 'up' | 'down'
    response_time_ms: number
    checked_at: string
}

interface Monitor {
    id: number
    name: string
    url: string
    is_active: boolean
    latest_check: LatestCheck | null
    uptime_24h: number | null
    active_incidents_count: number
}

const props = defineProps<{
    monitors: Monitor[]
    filters: { status: string | null }
}>()

const page = usePage()
const siteScope = computed((): SiteScope | null => (page.props as any).siteScope ?? null)
const isSiteScoped = computed(() => siteScope.value?.mode === 'site')

const { isLoading } = usePageLoading()

const stats = computed(() => {
    const total = props.monitors.length
    const up = props.monitors.filter(m => m.is_active && m.latest_check?.status === 'up').length
    const down = props.monitors.filter(m => m.is_active && m.latest_check?.status === 'down').length
    const withResponse = props.monitors.filter(m => m.latest_check?.response_time_ms)
    const avgMs = withResponse.length > 0
        ? Math.round(withResponse.reduce((sum, m) => sum + (m.latest_check?.response_time_ms || 0), 0) / withResponse.length)
        : 0
    return { total, up, down, avgMs }
})

const relativeTime = (dateStr: string | null): string => {
    if (!dateStr) return '-'
    const diffMs = Date.now() - new Date(dateStr).getTime()
    const mins = Math.floor(diffMs / 60000)
    if (mins < 1) return 'now'
    if (mins < 60) return `${mins}m`
    const hours = Math.floor(mins / 60)
    if (hours < 24) return `${hours}h`
    return `${Math.floor(hours / 24)}d`
}

const filterOptions = [
    { key: 'all', label: 'All' },
    { key: 'up', label: 'Up' },
    { key: 'down', label: 'Down' },
    { key: 'paused', label: 'Paused' }
]

const { value: activeFilter } = useSimplePersistentFilter(
    'monitors_status',
    props.filters.status || 'all',
    'monitors.index'
)

const searchQuery = ref('')
const debouncedQuery = ref('')

let debounceTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, (val) => {
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
        debouncedQuery.value = val.trim().toLowerCase()
    }, 250)
})

const filteredMonitors = computed(() => {
    const q = debouncedQuery.value
    if (!q) return props.monitors
    return props.monitors.filter(m =>
        m.name.toLowerCase().includes(q) || m.url.toLowerCase().includes(q)
    )
})

// Bulk selection — reactive Set: Vue 3 tracks add/delete/clear natively
const selectedIds = reactive(new Set<number>())
const showDeleteConfirm = ref(false)

const allVisibleSelected = computed(() => {
    if (filteredMonitors.value.length === 0) return false
    return filteredMonitors.value.every(m => selectedIds.has(m.id))
})

const someSelected = computed(() => selectedIds.size > 0)

const toggleSelectAll = () => {
    if (allVisibleSelected.value) {
        filteredMonitors.value.forEach(m => selectedIds.delete(m.id))
    } else {
        filteredMonitors.value.forEach(m => selectedIds.add(m.id))
    }
}

const toggleSelect = (id: number) => {
    if (selectedIds.has(id)) {
        selectedIds.delete(id)
    } else {
        selectedIds.add(id)
    }
}

const clearSelection = () => {
    selectedIds.clear()
}

const executeBulkAction = (action: 'pause' | 'resume' | 'delete') => {
    if (action === 'delete') {
        showDeleteConfirm.value = true
        return
    }
    router.post(route('monitors.bulk-action'), {
        action,
        ids: Array.from(selectedIds),
    }, {
        onSuccess: clearSelection,
    })
}

const confirmBulkDelete = () => {
    showDeleteConfirm.value = false
    router.post(route('monitors.bulk-action'), {
        action: 'delete',
        ids: Array.from(selectedIds),
    }, {
        onSuccess: clearSelection,
    })
}
</script>

<template>
    <Head title="Monitors" />

    <div class="space-y-8">
        <!-- Site scope banner — shown when filter is active -->
        <SiteScopeBanner />

        <!-- Header -->
        <PageHeader title="Monitors" description="Manage your uptime checks and settings.">
            <template #actions>
                <Link :href="route('monitors.create')">
                    <Button label="New Monitor" class="font-medium"><template #icon><Icon name="plus" /></template></Button>
                </Link>
            </template>
        </PageHeader>

        <!-- Filters & Stats Row -->
        <div class="flex flex-col gap-4 border-b border-white/5 pb-6">
            <div class="flex flex-col sm:flex-row gap-3">
                <!-- Search -->
                <div class="relative flex-1 max-w-sm">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-500 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Search monitors..."
                        class="w-full pl-9 pr-4 py-2 text-sm bg-white/5 border border-white/10 rounded-lg text-white placeholder-zinc-500 focus:outline-none focus:border-white/20 focus:bg-white/[0.07] transition-colors"
                        aria-label="Search monitors by name or URL"
                    />
                </div>
                <!-- Status filter -->
                <div class="flex items-center gap-2">
                    <SelectButton v-model="activeFilter" :options="filterOptions" optionLabel="label" optionValue="key" :allowEmpty="false" />
                </div>
            </div>
            <div class="flex items-center gap-6 text-sm">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span class="text-white font-medium">{{ stats.up }}</span>
                    <span class="text-zinc-500">Up</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                    <span class="text-white font-medium">{{ stats.down }}</span>
                    <span class="text-zinc-500">Down</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-white font-medium">{{ stats.avgMs }}ms</span>
                    <span class="text-zinc-500">Avg</span>
                </div>
                <div v-if="debouncedQuery" class="flex items-center gap-2 text-xs text-zinc-500">
                    <span>{{ filteredMonitors.length }} result{{ filteredMonitors.length !== 1 ? 's' : '' }}</span>
                </div>
            </div>
        </div>

        <!-- Linear List -->
        <SkeletonMonitorList v-if="isLoading" />
        <DataView v-else :value="filteredMonitors" :paginator="filteredMonitors.length > 20" :rows="20" class="bg-transparent">
            <template #list="slotProps">
                <div class="flex flex-col">
                    <!-- Select-all header row (only shown when list has items) -->
                    <div v-if="slotProps.items.length > 0" class="flex items-center gap-3 py-2 px-2 mb-1 border-b border-white/5">
                        <label class="flex items-center justify-center w-4 h-4 cursor-pointer" :aria-label="allVisibleSelected ? 'Deselect all monitors' : 'Select all monitors'">
                            <input
                                type="checkbox"
                                :checked="allVisibleSelected"
                                :indeterminate="someSelected && !allVisibleSelected"
                                @change="toggleSelectAll"
                                class="w-3.5 h-3.5 rounded border-white/20 bg-white/5 text-emerald-500 accent-emerald-500 cursor-pointer"
                                aria-label="Select all monitors"
                            />
                        </label>
                        <span class="text-xs text-zinc-500">Select all</span>
                    </div>

                    <div v-for="(monitor, index) in slotProps.items" :key="index"
                        class="group flex items-center py-4 border-b border-white/5 hover:bg-white/[0.02] transition-colors -mx-4 px-4 sm:mx-0 sm:px-2 rounded-lg gap-3"
                        :class="selectedIds.has(monitor.id) ? 'bg-emerald-500/[0.03]' : ''">

                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <!-- Checkbox -->
                            <label
                                class="flex items-center justify-center w-4 h-4 shrink-0 cursor-pointer"
                                @click.stop
                                :aria-label="`${selectedIds.has(monitor.id) ? 'Deselect' : 'Select'} ${monitor.name}`"
                            >
                                <input
                                    type="checkbox"
                                    :checked="selectedIds.has(monitor.id)"
                                    @change="toggleSelect(monitor.id)"
                                    class="w-3.5 h-3.5 rounded border-white/20 bg-white/5 accent-emerald-500 cursor-pointer"
                                />
                            </label>

                            <!-- Clickable row content -->
                            <div
                                class="flex items-center justify-between flex-1 min-w-0 cursor-pointer"
                                tabindex="0"
                                role="button"
                                @click="router.visit(route('monitors.show', monitor.id))"
                                @keydown.enter="router.visit(route('monitors.show', monitor.id))"
                                @keydown.space.prevent="router.visit(route('monitors.show', monitor.id))"
                                :aria-label="`View ${monitor.name} monitor details`"
                            >
                                <div class="flex items-center gap-4 min-w-0">
                                    <!-- Status Dot -->
                                    <div class="relative flex items-center justify-center w-4 h-4 shrink-0">
                                        <template v-if="!monitor.is_active">
                                            <div class="w-2.5 h-2.5 rounded-full border-2 border-zinc-600 bg-transparent" />
                                            <span class="sr-only">Status: Paused</span>
                                        </template>
                                        <template v-else-if="monitor.latest_check?.status === 'up'">
                                            <div class="w-2.5 h-2.5 rounded-full bg-emerald-500" />
                                            <span class="sr-only">Status: Up</span>
                                        </template>
                                        <template v-else>
                                            <span class="absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-25 animate-ping"></span>
                                            <div class="relative w-2.5 h-2.5 rounded-full bg-red-500" />
                                            <span class="sr-only">Status: Down</span>
                                        </template>
                                    </div>

                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-white font-medium tracking-tight truncate">{{ monitor.name }}</span>
                                            <span v-if="!monitor.is_active" class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-zinc-800 text-zinc-400">Paused</span>
                                        </div>
                                        <p class="text-xs text-zinc-500 font-mono truncate hover:text-emerald-400 transition-colors">{{ monitor.url }}</p>
                                    </div>
                                </div>

                                <!-- Metrics Column -->
                                <div class="flex items-center gap-8 md:gap-12 shrink-0 ml-4">
                                    <div class="hidden md:block text-right w-20">
                                        <span class="block text-sm font-medium" :class="monitor.uptime_24h > 98 ? 'text-emerald-500' : 'text-zinc-300'">{{ monitor.uptime_24h ?? '-' }}%</span>
                                        <span class="text-[10px] text-zinc-600 uppercase tracking-wider">Uptime</span>
                                    </div>

                                    <div class="hidden sm:block text-right w-16">
                                        <span class="block text-sm font-mono text-zinc-300">{{ monitor.latest_check?.response_time_ms ?? '-' }}ms</span>
                                        <span class="text-[10px] text-zinc-600 uppercase tracking-wider">Latency</span>
                                    </div>

                                    <div class="text-right w-16">
                                        <span class="block text-xs text-zinc-400">{{ relativeTime(monitor.latest_check?.checked_at) }}</span>
                                        <span class="text-[10px] text-zinc-600 uppercase tracking-wider">Checked</span>
                                    </div>

                                    <div class="text-zinc-600 group-hover:text-emerald-500 transition-colors">
                                        <Icon name="chevron-right" :size="14" />
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </template>
            <template #empty>
                <div class="flex flex-col items-center justify-center py-20 text-center border border-dashed border-white/5 rounded-2xl bg-white/[0.02]">
                    <template v-if="debouncedQuery">
                        <div class="p-4 mb-4 rounded-full bg-zinc-500/10">
                            <svg class="w-6 h-6 text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        </div>
                        <h3 class="text-lg font-medium text-white">No monitors found</h3>
                        <p class="text-zinc-500 mt-1 max-w-sm">No monitors match "<span class="text-zinc-300">{{ debouncedQuery }}</span>". Try a different search term.</p>
                        <button @click="searchQuery = ''" class="mt-4 text-sm text-emerald-500 hover:text-emerald-400 transition-colors">Clear search</button>
                    </template>
                    <template v-else-if="isSiteScoped">
                        <div class="p-4 mb-4 rounded-full bg-emerald-500/10">
                            <svg class="w-6 h-6 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-medium text-white">No monitors linked to {{ siteScope?.site?.name }}</h3>
                        <p class="text-zinc-500 mt-1 max-w-sm mb-6">Link existing monitors to this site, or create a new one.</p>
                        <div class="flex items-center gap-3">
                            <Link :href="route('monitors.index') + '?site=all'">
                                <Button label="View all monitors" severity="secondary" class="font-medium" />
                            </Link>
                            <Link :href="route('monitors.create')">
                                <Button label="New Monitor" class="font-medium"><template #icon><Icon name="plus" /></template></Button>
                            </Link>
                        </div>
                    </template>
                    <template v-else>
                        <div class="p-4 mb-4 rounded-full bg-white/5">
                            <Icon name="server" :size="24" class="text-zinc-400" />
                        </div>
                        <h3 class="text-lg font-medium text-white">No monitors found</h3>
                        <p class="text-zinc-500 mt-1 max-w-sm mb-6">Get started by creating your first uptime monitor.</p>
                        <Link :href="route('monitors.create')">
                            <Button label="Add Monitor"><template #icon><Icon name="plus" /></template></Button>
                        </Link>
                    </template>
                </div>
            </template>
        </DataView>
    </div>

    <!-- Floating Bulk Action Bar -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-4"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-4"
        >
            <div
                v-if="someSelected"
                class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 px-4 py-3 rounded-2xl shadow-2xl"
                style="background: linear-gradient(135deg, rgba(24, 24, 27, 0.95) 0%, rgba(17, 17, 19, 0.98) 100%); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.1);"
                role="toolbar"
                :aria-label="`${selectedIds.size} monitors selected`"
            >
                <span class="text-sm font-medium text-zinc-300 pr-1 border-r border-white/10 mr-1">
                    {{ selectedIds.size }} selected
                </span>
                <button
                    @click="executeBulkAction('pause')"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-amber-500/15 text-amber-400 border border-amber-500/25 hover:bg-amber-500/25 transition-colors"
                    aria-label="Pause selected monitors"
                >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>
                    </svg>
                    Pause
                </button>
                <button
                    @click="executeBulkAction('resume')"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-emerald-500/15 text-emerald-400 border border-emerald-500/25 hover:bg-emerald-500/25 transition-colors"
                    aria-label="Resume selected monitors"
                >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    Resume
                </button>
                <button
                    @click="executeBulkAction('delete')"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-red-500/15 text-red-400 border border-red-500/25 hover:bg-red-500/25 transition-colors"
                    aria-label="Delete selected monitors"
                >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Delete
                </button>
                <button
                    @click="clearSelection"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-zinc-500 hover:text-zinc-300 hover:bg-white/5 transition-colors"
                    aria-label="Cancel selection"
                >
                    Cancel
                </button>
            </div>
        </Transition>
    </Teleport>

    <!-- Bulk Delete Confirmation -->
    <Dialog
        v-model:visible="showDeleteConfirm"
        modal
        header="Delete Monitors"
        :style="{ width: '380px' }"
        class="!bg-[#111113] !border-white/10"
    >
        <p class="text-sm text-zinc-300 leading-relaxed">
            Are you sure you want to delete <span class="text-white font-medium">{{ selectedIds.size }} monitor{{ selectedIds.size !== 1 ? 's' : '' }}</span>?
            This action cannot be undone.
        </p>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button
                    @click="showDeleteConfirm = false"
                    class="px-4 py-2 rounded-lg text-sm text-zinc-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors"
                >
                    Cancel
                </button>
                <button
                    @click="confirmBulkDelete"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-red-600 hover:bg-red-700 border border-red-600 transition-colors"
                >
                    Delete {{ selectedIds.size }} monitor{{ selectedIds.size !== 1 ? 's' : '' }}
                </button>
            </div>
        </template>
    </Dialog>
</template>
