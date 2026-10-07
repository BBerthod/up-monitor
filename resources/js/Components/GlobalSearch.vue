<script setup lang="ts">
import { ref, computed, watch, toRef, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useFocusTrap } from '@/Composables/useFocusTrap'

interface Monitor {
    id: number
    name: string
    url: string
    is_active: boolean
    status: 'up' | 'down' | null
}

interface Incident {
    id: number
    monitor_id: number
    monitor_name: string
    cause: string
    started_at: string
    resolved_at: string | null
}

interface NotificationChannel {
    id: number
    name: string
    type: string
    is_active: boolean
}

interface StatusPage {
    id: number
    name: string
    slug: string
    is_active: boolean
}

interface Site {
    id: number
    name: string
    domain: string
}

interface Server {
    id: number
    name: string
    is_active: boolean
}

interface SearchResult {
    monitors: Monitor[]
    incidents: Incident[]
    notification_channels: NotificationChannel[]
    status_pages: StatusPage[]
    sites?: Site[]
    servers?: Server[]
}

const isOpen = ref(false)
const query = ref('')
const results = ref<SearchResult>({
    monitors: [],
    incidents: [],
    notification_channels: [],
    status_pages: [],
    sites: [],
    servers: [],
})
const loading = ref(false)
const selectedIndex = ref(0)
const containerRef = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)
const isOpenRef = toRef(isOpen)
const recentSearches = ref<string[]>([])

useFocusTrap(containerRef, isOpenRef)

const flattenedResults = computed(() => {
    const items: { type: string; item: Monitor | Incident | NotificationChannel | StatusPage; group: string }[] = []

    if (results.value.monitors.length > 0) {
        items.push({ type: 'header', item: {} as Monitor, group: 'Monitors' })
        results.value.monitors.forEach(m => items.push({ type: 'monitor', item: m, group: 'Monitors' }))
    }
    if (results.value.incidents.length > 0) {
        items.push({ type: 'header', item: {} as Incident, group: 'Incidents' })
        results.value.incidents.forEach(i => items.push({ type: 'incident', item: i, group: 'Incidents' }))
    }
    if (results.value.notification_channels.length > 0) {
        items.push({ type: 'header', item: {} as NotificationChannel, group: 'Notification Channels' })
        results.value.notification_channels.forEach(c => items.push({ type: 'channel', item: c, group: 'Notification Channels' }))
    }
    if (results.value.status_pages.length > 0) {
        items.push({ type: 'header', item: {} as StatusPage, group: 'Status Pages' })
        results.value.status_pages.forEach(s => items.push({ type: 'status_page', item: s, group: 'Status Pages' }))
    }
    if ((results.value.sites ?? []).length > 0) {
        items.push({ type: 'header', item: {} as Site, group: 'Sites' })
        ;(results.value.sites ?? []).forEach(s => items.push({ type: 'site', item: s, group: 'Sites' }))
    }
    if ((results.value.servers ?? []).length > 0) {
        items.push({ type: 'header', item: {} as Server, group: 'Servers' })
        ;(results.value.servers ?? []).forEach(s => items.push({ type: 'server_result', item: s, group: 'Servers' }))
    }

    return items
})

const selectableCount = computed(() =>
    results.value.monitors.length +
    results.value.incidents.length +
    results.value.notification_channels.length +
    results.value.status_pages.length +
    (results.value.sites?.length ?? 0) +
    (results.value.servers?.length ?? 0)
)

const hasResults = computed(() => selectableCount.value > 0)

let debounceTimer: ReturnType<typeof setTimeout> | null = null
let abortController: AbortController | null = null

watch(query, (val) => {
    if (debounceTimer) clearTimeout(debounceTimer)

    if (val.length < 2) {
        // Cancel any in-flight request when the query is cleared
        abortController?.abort()
        abortController = null
        results.value = { monitors: [], incidents: [], notification_channels: [], status_pages: [], sites: [], servers: [] }
        return
    }

    debounceTimer = setTimeout(() => search(), 200)
})

const search = async () => {
    if (query.value.length < 2) return

    // Abort any previous in-flight request to prevent out-of-order results
    abortController?.abort()
    abortController = new AbortController()

    loading.value = true
    try {
        const response = await fetch(`/api/search?q=${encodeURIComponent(query.value)}`, {
            headers: { 'Accept': 'application/json' },
            credentials: 'include',
            signal: abortController.signal,
        })
        if (response.ok) {
            results.value = await response.json()
            selectedIndex.value = 0
        }
    } catch (e) {
        if (e instanceof Error && e.name === 'AbortError') return
        results.value = { monitors: [], incidents: [], notification_channels: [], status_pages: [] }
    } finally {
        loading.value = false
    }
}

const open = () => {
    isOpen.value = true
    selectedIndex.value = 0
    setTimeout(() => inputRef.value?.focus(), 50)
}

const close = () => {
    isOpen.value = false
    query.value = ''
    results.value = { monitors: [], incidents: [], notification_channels: [], status_pages: [], sites: [], servers: [] }
}

const navigate = (type: string, item: Monitor | Incident | NotificationChannel | StatusPage) => {
    saveRecentSearch(query.value)
    close()

    switch (type) {
        case 'monitor':
            router.visit(route('monitors.show', (item as Monitor).id))
            break
        case 'incident':
            router.visit(route('monitors.show', (item as Incident).monitor_id))
            break
        case 'channel':
            router.visit(route('channels.edit', (item as NotificationChannel).id))
            break
        case 'status_page':
            router.visit(route('status-pages.edit', (item as StatusPage).id))
            break
        case 'site':
            router.visit(route('sites.show', (item as Site).id))
            break
        case 'server_result':
            router.visit(route('servers.show', (item as Server).id))
            break
    }
}

const handleKeydown = (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault()
        if (isOpen.value) {
            close()
        } else {
            open()
        }
        return
    }

    if (!isOpen.value) return

    if (e.key === 'Escape') {
        e.preventDefault()
        close()
        return
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault()
        selectedIndex.value = Math.min(selectedIndex.value + 1, selectableCount.value - 1)
        scrollToSelected()
        return
    }

    if (e.key === 'ArrowUp') {
        e.preventDefault()
        selectedIndex.value = Math.max(selectedIndex.value - 1, 0)
        scrollToSelected()
        return
    }

    if (e.key === 'Enter' && hasResults.value) {
        e.preventDefault()
        const selectable = flattenedResults.value.filter(r => r.type !== 'header')
        if (selectable[selectedIndex.value]) {
            navigate(selectable[selectedIndex.value].type, selectable[selectedIndex.value].item)
        }
    }
}

const scrollToSelected = () => {
    setTimeout(() => {
        const el = document.querySelector(`[data-index="${selectedIndex.value}"]`)
        el?.scrollIntoView({ block: 'nearest' })
    }, 0)
}

const saveRecentSearch = (q: string) => {
    if (!q || q.length < 2) return
    const searches = [q, ...recentSearches.value.filter(s => s !== q)].slice(0, 5)
    recentSearches.value = searches
    localStorage.setItem('recentSearches', JSON.stringify(searches))
}

const loadRecentSearches = () => {
    try {
        const stored = localStorage.getItem('recentSearches')
        if (stored) {
            recentSearches.value = JSON.parse(stored)
        }
    } catch {
        recentSearches.value = []
    }
}

const applyRecentSearch = (q: string) => {
    query.value = q
    search()
}

const handleOpenSearch = () => open()

onMounted(() => {
    document.addEventListener('keydown', handleKeydown)
    document.addEventListener('open-global-search', handleOpenSearch)
    loadRecentSearches()
})

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown)
    document.removeEventListener('open-global-search', handleOpenSearch)
    abortController?.abort()
})
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="isOpen" class="fixed inset-0 z-[60] flex items-start justify-center pt-[15vh]">
                <div class="absolute inset-0 bg-black/60" @click="close" />

                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div
                        v-if="isOpen"
                        ref="containerRef"
                        class="relative w-full max-w-xl mx-4 bg-[#18181b] border border-white/10 rounded-xl shadow-2xl overflow-hidden"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Global search"
                    >
                        <div class="flex items-center gap-3 px-4 border-b border-white/5">
                            <svg class="w-5 h-5 text-zinc-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                            </svg>
                            <input
                                ref="inputRef"
                                v-model="query"
                                type="text"
                                placeholder="Search monitors, incidents, channels..."
                                class="flex-1 bg-transparent py-4 text-white placeholder-zinc-500 outline-none text-sm"
                            />
                            <div v-if="loading" class="w-4 h-4 border-2 border-emerald-500/30 border-t-emerald-500 rounded-full animate-spin" />
                            <kbd v-else class="hidden sm:inline-flex items-center gap-1 px-2 py-1 text-[10px] font-medium text-zinc-500 bg-white/5 rounded border border-white/10">
                                ESC
                            </kbd>
                        </div>

                        <div class="max-h-[60vh] overflow-y-auto">
                            <template v-if="query.length < 2 && recentSearches.length > 0">
                                <div class="px-4 py-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                                    Recent Searches
                                </div>
                                <button
                                    v-for="(search, idx) in recentSearches"
                                    :key="idx"
                                    class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-zinc-300 hover:bg-white/5 transition-colors"
                                    @click="applyRecentSearch(search)"
                                >
                                    <svg class="w-4 h-4 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    {{ search }}
                                </button>
                            </template>

                            <template v-else-if="hasResults">
                                <template v-for="(result, idx) in flattenedResults" :key="idx">
                                    <div v-if="result.type === 'header'" class="px-4 py-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider bg-white/[0.02]">
                                        {{ result.group }}
                                    </div>
                                    <button
                                        v-else
                                        :data-index="flattenedResults.filter(r => r.type !== 'header').findIndex(r => r === result)"
                                        class="w-full flex items-center gap-3 px-4 py-2.5 text-left transition-colors"
                                        :class="flattenedResults.filter(r => r.type !== 'header').findIndex(r => r === result) === selectedIndex ? 'bg-white/5' : 'hover:bg-white/5'"
                                        @click="navigate(result.type, result.item)"
                                        @mouseenter="selectedIndex = flattenedResults.filter(r => r.type !== 'header').findIndex(r => r === result)"
                                    >
                                        <template v-if="result.type === 'monitor'">
                                            <div class="w-2 h-2 rounded-full shrink-0" :class="(result.item as Monitor).status === 'up' ? 'bg-emerald-500' : 'bg-red-500'" />
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm text-white truncate">{{ (result.item as Monitor).name }}</div>
                                                <div class="text-xs text-zinc-500 truncate font-mono">{{ (result.item as Monitor).url }}</div>
                                            </div>
                                            <svg class="w-4 h-4 text-zinc-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </template>

                                        <template v-else-if="result.type === 'incident'">
                                            <div class="w-2 h-2 rounded-full shrink-0" :class="(result.item as Incident).resolved_at ? 'bg-zinc-500' : 'bg-red-500'" />
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm text-white truncate">{{ (result.item as Incident).monitor_name }}</div>
                                                <div class="text-xs text-zinc-500">{{ (result.item as Incident).cause }}</div>
                                            </div>
                                            <svg class="w-4 h-4 text-zinc-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </template>

                                        <template v-else-if="result.type === 'channel'">
                                            <div class="w-2 h-2 rounded-full shrink-0" :class="(result.item as NotificationChannel).is_active ? 'bg-emerald-500' : 'bg-zinc-500'" />
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm text-white truncate">{{ (result.item as NotificationChannel).name }}</div>
                                                <div class="text-xs text-zinc-500 capitalize">{{ (result.item as NotificationChannel).type }}</div>
                                            </div>
                                            <svg class="w-4 h-4 text-zinc-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </template>

                                        <template v-else-if="result.type === 'status_page'">
                                            <div class="w-2 h-2 rounded-full shrink-0" :class="(result.item as StatusPage).is_active ? 'bg-emerald-500' : 'bg-zinc-500'" />
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm text-white truncate">{{ (result.item as StatusPage).name }}</div>
                                                <div class="text-xs text-zinc-500 truncate font-mono">/{{ (result.item as StatusPage).slug }}</div>
                                            </div>
                                            <svg class="w-4 h-4 text-zinc-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </template>

                                        <template v-else-if="result.type === 'site'">
                                            <svg class="w-4 h-4 text-zinc-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>
                                            </svg>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm text-white truncate">{{ (result.item as Site).name }}</div>
                                                <div class="text-xs text-zinc-500 truncate font-mono">{{ (result.item as Site).domain }}</div>
                                            </div>
                                            <svg class="w-4 h-4 text-zinc-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </template>

                                        <template v-else-if="result.type === 'server_result'">
                                            <div class="w-2 h-2 rounded-full shrink-0" :class="(result.item as Server).is_active ? 'bg-emerald-500' : 'bg-zinc-500'" />
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm text-white truncate">{{ (result.item as Server).name }}</div>
                                                <div class="text-xs text-zinc-500">Server</div>
                                            </div>
                                            <svg class="w-4 h-4 text-zinc-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </template>
                                    </button>
                                </template>
                            </template>

                            <template v-else-if="query.length >= 2 && !loading">
                                <div class="flex flex-col items-center justify-center py-12 text-center">
                                    <svg class="w-8 h-8 text-zinc-600 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                                    </svg>
                                    <p class="text-sm text-zinc-500">No results found for "{{ query }}"</p>
                                </div>
                            </template>
                        </div>

                        <div class="flex items-center justify-between px-4 py-2 border-t border-white/5 text-xs text-zinc-500">
                            <div class="flex items-center gap-4">
                                <span class="flex items-center gap-1">
                                    <kbd class="px-1.5 py-0.5 bg-white/5 rounded border border-white/10 text-[10px]">↑</kbd>
                                    <kbd class="px-1.5 py-0.5 bg-white/5 rounded border border-white/10 text-[10px]">↓</kbd>
                                    to navigate
                                </span>
                                <span class="flex items-center gap-1">
                                    <kbd class="px-1.5 py-0.5 bg-white/5 rounded border border-white/10 text-[10px]">↵</kbd>
                                    to select
                                </span>
                            </div>
                            <span class="hidden sm:flex items-center gap-1">
                                <kbd class="px-1.5 py-0.5 bg-white/5 rounded border border-white/10 text-[10px]">⌘</kbd>
                                <kbd class="px-1.5 py-0.5 bg-white/5 rounded border border-white/10 text-[10px]">K</kbd>
                                to close
                            </span>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
