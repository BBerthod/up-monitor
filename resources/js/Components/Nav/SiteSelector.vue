<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import type { SiteScope } from '@/Types/page'

interface NavSite {
    id: number
    name: string
    domain: string
}

const page = usePage()

// navSites arrives via shared Inertia props — defensive: may be absent during initial load
const navSites = computed((): NavSite[] => (page.props as any).navSites ?? [])

// siteScope — may be absent before backend delivers it (defensive)
const siteScope = computed((): SiteScope | null => (page.props as any).siteScope ?? null)

const isScoped = computed(() => siteScope.value && siteScope.value.mode !== 'all')
const isUnassigned = computed(() => siteScope.value?.mode === 'unassigned')
const isSite = computed(() => siteScope.value?.mode === 'site')

const triggerLabel = computed(() => {
    if (isSite.value && siteScope.value?.site) return siteScope.value.site.name
    if (isUnassigned.value) return 'Unassigned'
    return 'All sites'
})

const isOpen = ref(false)
const searchQuery = ref('')
const containerRef = ref<HTMLElement | null>(null)

const filteredSites = computed(() => {
    const q = searchQuery.value.toLowerCase().trim()
    if (!q) return navSites.value
    return navSites.value.filter(
        s => s.name.toLowerCase().includes(q) || s.domain.toLowerCase().includes(q)
    )
})

const open = () => {
    isOpen.value = true
    searchQuery.value = ''
}

const close = () => {
    isOpen.value = false
    searchQuery.value = ''
}

// Changing scope stays on the CURRENT page: the ResolveSiteScope middleware
// reads ?site= on any route and persists it in the session, so there is no
// reason to eject the user to the dashboard (losing their filters and
// scroll), which is what the previous hard-coded destinations did.
//
// Two details matter:
//  - the rest of the query string is preserved, otherwise applying a scope
//    silently wiped the filters the page had in its URL;
//  - /sites/{id} is bound to one site, so staying put there would leave the
//    page rendering site A while the scope pill says site B. Picking a site
//    from that page navigates to it instead.
const applyScope = (value: string) => {
    close()

    const params = new URLSearchParams(window.location.search)
    params.set('site', value)

    const onSiteDetail = /^\/sites\/\d+$/.test(window.location.pathname)
    let path = window.location.pathname

    if (onSiteDetail) {
        // Another site → its own detail page; "all"/"unassigned" have no
        // single-site page to land on, so fall back to the list.
        path = /^\d+$/.test(value) ? `/sites/${value}` : '/sites'
    }

    router.visit(`${path}?${params.toString()}`, { preserveScroll: true })
}

const selectAll = () => applyScope('all')

const selectUnassigned = () => applyScope('unassigned')

const selectSite = (site: NavSite) => applyScope(String(site.id))

const onKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') close()
}

const onClickOutside = (e: MouseEvent) => {
    if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
        close()
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    document.addEventListener('mousedown', onClickOutside)
})

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown)
    document.removeEventListener('mousedown', onClickOutside)
})
</script>

<template>
    <div ref="containerRef" class="relative">
        <!-- Trigger button — visual state reflects scope -->
        <button
            @click="isOpen ? close() : open()"
            :aria-expanded="isOpen"
            aria-haspopup="listbox"
            :class="[
                'flex items-center gap-2 h-8 px-3 text-sm text-zinc-400 rounded-md transition-colors max-w-[200px]',
                isScoped
                    ? 'bg-zinc-950 border border-emerald-500/40 hover:border-emerald-500/60'
                    : 'bg-zinc-950 border border-zinc-800 hover:border-[#34353b]',
            ]"
        >
            <!-- globe icon -->
            <svg
                class="w-4 h-4 shrink-0"
                :class="isScoped ? 'text-emerald-500' : 'text-zinc-500'"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            >
                <circle cx="12" cy="12" r="10"/>
                <line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>

            <!-- Scope indicator dot -->
            <span
                v-if="isSite"
                class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0 -ml-0.5"
                aria-hidden="true"
            />
            <span
                v-else-if="isUnassigned"
                class="w-1.5 h-1.5 rounded-full bg-amber-400 shrink-0 -ml-0.5"
                aria-hidden="true"
            />

            <span
                class="truncate text-xs"
                :class="isScoped ? 'text-white' : ''"
            >{{ triggerLabel }}</span>

            <svg class="w-3 h-3 text-zinc-600 shrink-0 ml-auto" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 9l6 6 6-6"/>
            </svg>
        </button>

        <!-- Dropdown -->
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0 translate-y-1 scale-95"
            enter-to-class="opacity-100 translate-y-0 scale-100"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100 translate-y-0 scale-100"
            leave-to-class="opacity-0 translate-y-1 scale-95"
        >
            <div
                v-if="isOpen"
                class="absolute top-full left-0 mt-1.5 w-64 bg-zinc-900 border border-zinc-800 rounded-md shadow-[var(--shadow-popup)] z-50 overflow-hidden origin-top-left"
                role="listbox"
                aria-label="Select site scope"
            >
                <!-- Search input -->
                <div class="flex items-center gap-2 px-3 py-2 border-b border-white/5">
                    <svg class="w-3.5 h-3.5 text-zinc-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Filter sites…"
                        class="flex-1 bg-transparent text-xs text-white placeholder-zinc-600 outline-none"
                        @click.stop
                    />
                </div>

                <div class="max-h-72 overflow-y-auto py-1">
                    <!-- All sites option -->
                    <button
                        @click="selectAll"
                        class="flex items-center gap-2.5 w-full px-3 py-2 text-sm hover:bg-white/5 transition-colors"
                        :class="!isScoped ? 'text-white' : 'text-zinc-400'"
                        role="option"
                        :aria-selected="!isScoped"
                    >
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0" />
                        <span class="font-medium text-xs">All sites</span>
                    </button>

                    <!-- Unassigned option -->
                    <button
                        @click="selectUnassigned"
                        class="flex items-center gap-2.5 w-full px-3 py-2 hover:bg-white/5 transition-colors"
                        :class="isUnassigned ? 'text-white' : 'text-zinc-400'"
                        role="option"
                        :aria-selected="isUnassigned"
                    >
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400 shrink-0" />
                        <div class="min-w-0 flex-1 text-left">
                            <div class="text-xs font-medium leading-tight">Unassigned</div>
                            <div class="text-[10px] text-zinc-600 leading-tight">Items not linked to a site</div>
                        </div>
                    </button>

                    <!-- Separator -->
                    <div v-if="navSites.length > 0 && !searchQuery" class="my-1 border-t border-white/5" />

                    <!-- Individual sites -->
                    <template v-if="filteredSites.length > 0 || searchQuery">
                        <button
                            v-for="site in filteredSites"
                            :key="site.id"
                            @click="selectSite(site)"
                            class="flex items-center gap-2.5 w-full px-3 py-2 text-left hover:bg-white/5 transition-colors"
                            :class="isSite && siteScope?.site?.id === site.id ? 'text-white bg-emerald-500/5' : 'text-zinc-400'"
                            role="option"
                            :aria-selected="isSite && siteScope?.site?.id === site.id"
                        >
                            <svg class="w-3.5 h-3.5 text-zinc-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>
                            </svg>
                            <div class="min-w-0 flex-1">
                                <div class="text-xs text-white truncate">{{ site.name }}</div>
                                <div class="text-[10px] text-zinc-600 truncate font-mono">{{ site.domain }}</div>
                            </div>
                            <!-- Active checkmark -->
                            <svg
                                v-if="isSite && siteScope?.site?.id === site.id"
                                class="w-3 h-3 text-emerald-500 shrink-0"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                            >
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </button>
                    </template>

                    <!-- Empty state when filtering -->
                    <div v-if="filteredSites.length === 0 && searchQuery" class="px-3 py-4 text-center text-xs text-zinc-600">
                        No sites match "{{ searchQuery }}"
                    </div>

                    <!-- No sites configured -->
                    <div v-if="navSites.length === 0 && !searchQuery" class="px-3 py-3 text-center text-xs text-zinc-600">
                        No sites configured yet
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>
