<script setup lang="ts">
import { defineAsyncComponent, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

import Toast from 'primevue/toast'
import Menu from 'primevue/menu'
import Drawer from 'primevue/drawer'
import { useFlashToast } from '@/Composables/useFlashToast'
import { useAuth } from '@/Composables/useAuth'
import { useEcho } from '@/Composables/useEcho'

import InstallPrompt from '@/Components/InstallPrompt.vue'
import SidebarNav from '@/Components/Nav/SidebarNav.vue'
import SiteSelector from '@/Components/Nav/SiteSelector.vue'
import Icon from '@/Components/Icon.vue'
import type { IconName } from '@/Components/Icon.vue'

const { user, team } = useAuth()

useFlashToast()
useEcho()

const sidebarOpen = ref(true)
const mobileMenuOpen = ref(false)
const userMenu = ref()
const searchLoaded = ref(false)

let searchModule: Promise<typeof import('@/Components/GlobalSearch.vue')> | null = null
const loadGlobalSearch = () => searchModule ??= import('@/Components/GlobalSearch.vue')
const GlobalSearch = defineAsyncComponent(loadGlobalSearch)

const preloadSearch = () => {
    searchLoaded.value = true
    return loadGlobalSearch()
}

const openSearch = async () => {
    await preloadSearch()
    await nextTick()
    window.setTimeout(() => document.dispatchEvent(new Event('open-global-search')), 0)
}

const handleSearchShortcut = (event: KeyboardEvent) => {
    if (!searchLoaded.value && (event.metaKey || event.ctrlKey) && event.key === 'k') {
        event.preventDefault()
        void openSearch()
    }
}

const toggleUserMenu = (event: Event) => {
    userMenu.value.toggle(event)
}

const userMenuItems: { label: string; icon: IconName; command: () => void }[] = [
    {
        label: 'My Profile',
        icon: 'user',
        command: () => router.get(route('profile.edit'))
    },
    {
        label: 'Settings',
        icon: 'cog',
        command: () => router.get(route('settings.index'))
    },
    {
        label: 'Log Out',
        icon: 'log-out',
        command: () => router.post('/logout')
    }
]

onMounted(() => {
    document.addEventListener('keydown', handleSearchShortcut)

    if (window.innerWidth < 1024) {
        sidebarOpen.value = false
    }
})

onUnmounted(() => {
    document.removeEventListener('keydown', handleSearchShortcut)
})
</script>

<template>
    <GlobalSearch v-if="searchLoaded" />
    <Toast position="top-right">
        <template #message="slotProps">
            <div class="flex items-start justify-between gap-3 w-full">
                <div class="flex flex-col gap-1 flex-1 min-w-0">
                    <span class="font-semibold text-sm">{{ slotProps.message.summary }}</span>
                    <span class="text-sm opacity-90">{{ slotProps.message.detail }}</span>
                </div>
                <button
                    v-if="slotProps.message.data?.link && slotProps.message.data?.linkText"
                    @click="router.visit(slotProps.message.data.link)"
                    class="shrink-0 px-3 py-1.5 text-xs font-medium rounded-md bg-white/10 hover:bg-white/20 text-white transition-colors"
                >
                    {{ slotProps.message.data.linkText }}
                </button>
            </div>
        </template>
    </Toast>

    <div class="min-h-screen bg-page text-zinc-100 font-sans lg:flex selection:bg-emerald-500/30 selection:text-white">

        <!-- ─── Mobile drawer ─── -->
        <Drawer v-model:visible="mobileMenuOpen" header="Menu" class="lg:hidden border-r border-zinc-800 bg-page">
            <template #header>
                <div class="flex items-center gap-2">
                    <span class="flex items-center justify-center w-7 h-7 rounded-md border border-zinc-800 bg-zinc-950">
                        <svg class="w-4 h-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path d="M12 19V5M5 12l7-7 7 7" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="text-base font-semibold text-zinc-100">Up <span class="text-xs font-normal text-zinc-500">by Radiank</span></span>
                </div>
            </template>

            <div class="mt-2 flex flex-col h-full">
                <SidebarNav :collapsed="false" :on-item-click="() => mobileMenuOpen = false" />
            </div>
        </Drawer>

        <!-- ─── Desktop sidebar ───
             The column stretches with the page (so its border and background run
             to the very bottom of long pages); the inner panel sticks to the
             viewport so the navigation stays reachable while scrolling. -->
        <aside :class="[
            'hidden lg:block shrink-0 bg-page border-r border-zinc-800 transition-[width] duration-200',
            sidebarOpen ? 'w-60' : 'w-16'
        ]">
            <div class="sticky top-0 h-screen flex flex-col">
                <!-- Logo -->
                <div :class="['flex items-center h-14 shrink-0 border-b border-zinc-800', sidebarOpen ? 'px-5' : 'justify-center']">
                    <a href="/dashboard" class="flex items-center gap-2">
                        <span class="flex items-center justify-center w-7 h-7 rounded-md border border-zinc-800 bg-zinc-950">
                            <svg class="w-4 h-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path d="M12 19V5M5 12l7-7 7 7" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span v-if="sidebarOpen" class="text-base font-semibold text-zinc-100">Up <span class="text-xs font-normal text-zinc-500">by Radiank</span></span>
                        <span v-else class="sr-only">Up by Radiank</span>
                    </a>
                </div>

                <!-- Nav — scrollable region. `min-h-0` is required: a flex
                     child's default min-height is `auto`, which ignores
                     `overflow-y-auto` and lets the nav's true content height
                     push (and get covered by) whatever comes after it. -->
                <div class="flex-1 min-h-0 flex flex-col overflow-y-auto overflow-x-hidden py-2">
                    <SidebarNav :collapsed="!sidebarOpen" />
                </div>

                <!-- Collapse toggle -->
                <div class="p-3 border-t border-zinc-800 shrink-0">
                    <button
                        @click="sidebarOpen = !sidebarOpen"
                        :aria-label="sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'"
                        class="flex items-center justify-center w-full p-2 text-zinc-500 hover:text-zinc-100 rounded-md hover:bg-zinc-900 transition-colors"
                    >
                        <Icon name="chevron-left" :class="['transition-transform', !sidebarOpen && 'rotate-180']" />
                    </button>
                </div>
            </div>
        </aside>

        <!-- ─── Main content area ─── -->
        <div class="flex-1 min-w-0">

            <!-- Top bar -->
            <header class="sticky top-0 z-30 h-14 bg-page border-b border-zinc-800">
                <div class="flex items-center justify-between h-full px-4 sm:px-6 lg:px-8">

                    <!-- Left: hamburger (mobile) + search + site selector -->
                    <div class="flex items-center gap-3">
                        <button
                            @click="mobileMenuOpen = true"
                            class="lg:hidden p-2 -ml-2 text-zinc-400 hover:text-zinc-100 rounded-md hover:bg-zinc-900"
                            aria-label="Open navigation menu"
                            :aria-expanded="mobileMenuOpen"
                        >
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <line x1="3" y1="6" x2="21" y2="6"/>
                                <line x1="3" y1="12" x2="21" y2="12"/>
                                <line x1="3" y1="18" x2="21" y2="18"/>
                            </svg>
                        </button>

                        <!-- Site selector -->
                        <SiteSelector class="hidden sm:block" />

                        <!-- Global search -->
                        <button
                            @click="openSearch"
                            @focus="preloadSearch"
                            class="hidden sm:flex items-center gap-2 h-8 px-3 text-[13px] text-zinc-400 bg-zinc-950 border border-zinc-800 rounded-md hover:border-[#34353b] hover:text-zinc-100 transition-colors"
                            aria-label="Open search"
                        >
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                            </svg>
                            <span class="hidden md:inline">Search…</span>
                            <kbd class="hidden md:inline-flex items-center gap-0.5 px-1.5 py-0.5 font-mono text-[11px] text-zinc-400 rounded border border-zinc-800">
                                ⌘K
                            </kbd>
                        </button>
                    </div>

                    <!-- Right: team + user menu -->
                    <div class="flex items-center gap-3">
                        <span v-if="team" class="hidden md:inline-flex items-center h-8 px-3 rounded-md border border-zinc-800 text-xs font-medium text-zinc-400">
                            {{ team.name }}
                        </span>

                        <div class="relative">
                            <Menu ref="userMenu" :model="userMenuItems" :popup="true" class="!min-w-40">
                                <template #itemicon="{ item }">
                                    <Icon :name="(item.icon as IconName)" class="text-zinc-400" />
                                </template>
                            </Menu>
                            <button
                                @click="toggleUserMenu"
                                class="flex items-center justify-center w-8 h-8 rounded-md border border-zinc-800 bg-zinc-900 text-sm font-medium text-zinc-200 hover:border-[#34353b] hover:text-white transition-colors"
                                aria-label="Open user menu"
                                aria-haspopup="true"
                            >
                                {{ user?.name?.charAt(0)?.toUpperCase() || 'U' }}
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto">
                <slot />

                <!-- Thin, closable, in-flow banner — never `fixed`, so it can
                     never float over page content or sidebar nav items. -->
                <InstallPrompt />
            </main>
        </div>
    </div>
</template>
