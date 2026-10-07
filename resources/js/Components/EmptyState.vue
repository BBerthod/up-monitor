<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
    title: string
    description?: string
    icon?: 'check' | 'server' | 'bell' | 'globe' | 'chart'
    iconColor?: 'emerald' | 'red' | 'amber' | 'slate'
}>(), {
    iconColor: 'emerald',
})

const colorConfig = computed(() => {
    // "emerald" historically meant "all clear": an empty, healthy state stays
    // calm grey — colour is reserved for problems (amber, red).
    const colors: Record<string, { bg: string; text: string }> = {
        emerald: { bg: 'border-zinc-800', text: 'text-zinc-400' },
        red: { bg: 'border-red-500/30 bg-red-500/10', text: 'text-red-400' },
        amber: { bg: 'border-amber-500/30 bg-amber-500/10', text: 'text-amber-400' },
        slate: { bg: 'border-zinc-800', text: 'text-zinc-400' },
    }
    return colors[props.iconColor] ?? colors.emerald
})
</script>

<template>
    <div class="flex flex-col items-center justify-center py-12 px-6 text-center border border-dashed border-zinc-800 rounded-md">
        <div v-if="icon" class="p-2.5 mb-4 rounded-md border" :class="colorConfig.bg">
            <svg v-if="icon === 'check'" class="w-5 h-5" :class="colorConfig.text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
            </svg>
            <svg v-else-if="icon === 'server'" class="w-5 h-5" :class="colorConfig.text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
            </svg>
            <svg v-else-if="icon === 'bell'" class="w-5 h-5" :class="colorConfig.text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <svg v-else-if="icon === 'globe'" class="w-5 h-5" :class="colorConfig.text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10" /><line x1="2" y1="12" x2="22" y2="12" /><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
            </svg>
            <svg v-else-if="icon === 'chart'" class="w-5 h-5" :class="colorConfig.text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
        </div>

        <h4 class="text-zinc-100 font-medium">{{ title }}</h4>
        <p v-if="description" class="text-sm text-zinc-400 mt-1 max-w-sm">{{ description }}</p>

        <div v-if="$slots.action" class="mt-6">
            <slot name="action" />
        </div>
    </div>
</template>
