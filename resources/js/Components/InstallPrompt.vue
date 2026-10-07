<script setup lang="ts">
import { usePwaInstall } from '@/Composables/usePwaInstall'

const { showPrompt, install, dismiss } = usePwaInstall()
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <!-- Thin, closable banner in normal document flow, always placed
             after the page content: it pushes the page taller instead of
             floating over it, so it can never cover a card or a sidebar nav
             item, on any page length or viewport size. -->
        <div
            v-if="showPrompt"
            class="mt-6 flex items-center gap-3 px-3 py-2 rounded-md bg-zinc-900 border border-zinc-800"
            role="banner"
            aria-label="Install app prompt"
        >
            <div class="shrink-0 flex items-center justify-center w-6 h-6 rounded-md bg-zinc-950 border border-zinc-800">
                <svg class="w-3.5 h-3.5 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 19V5M5 12l7-7 7 7"/>
                </svg>
            </div>

            <p class="flex-1 min-w-0 text-xs text-zinc-400">
                <span class="text-zinc-100 font-medium">Install Up</span> for quick access — works offline, no browser chrome.
            </p>

            <button
                @click="install"
                class="shrink-0 px-3 py-1.5 text-xs font-medium text-zinc-950 bg-emerald-500 hover:bg-emerald-400 rounded-md transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 focus-visible:ring-offset-zinc-900"
                aria-label="Install the app"
            >
                Install
            </button>

            <button
                @click="dismiss"
                class="shrink-0 p-1 text-zinc-400 hover:text-zinc-100 rounded-md hover:bg-zinc-800 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/20"
                aria-label="Dismiss install prompt"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    </Transition>
</template>
