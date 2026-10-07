<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{ title?: string }>()
</script>

<template>
    <div class="min-h-screen bg-page flex flex-col items-center justify-center px-4 py-12">
        <div class="w-full max-w-sm">
            <!-- Logo -->
            <Link href="/" class="flex items-center justify-center gap-2 mb-8">
                <span class="flex items-center justify-center w-8 h-8 rounded-md border border-zinc-800 bg-zinc-950">
                    <svg class="w-4 h-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path d="M12 19V5M5 12l7-7 7 7" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="text-xl font-semibold text-zinc-100">Up <span class="text-sm font-normal text-zinc-400">by Radiank</span></span>
            </Link>

            <h1 v-if="title" class="text-center text-base font-semibold text-zinc-100 mb-4">{{ title }}</h1>

            <div class="glass p-6 sm:p-8">
                <slot />
            </div>

            <div class="mt-6 text-center text-sm text-zinc-400">
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>
