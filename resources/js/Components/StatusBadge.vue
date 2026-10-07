<script setup lang="ts">
import { computed } from 'vue'
import Icon from '@/Components/Icon.vue'

const props = withDefaults(defineProps<{
    status: 'up' | 'down' | 'paused'
    size?: 'sm' | 'md'
    showLabel?: boolean
}>(), {
    size: 'sm',
    showLabel: true,
})

// Calm when up (grey + discreet check), loud only on failure: red, and the
// only state that pulses.
const config = computed(() => {
    const configs = {
        up: {
            label: 'UP',
            dotClass: 'bg-zinc-400',
            textClass: 'text-zinc-400',
            pillClass: 'border-zinc-800 text-zinc-300',
            pulse: false,
        },
        down: {
            label: 'DOWN',
            dotClass: 'bg-red-500',
            textClass: 'text-red-400',
            pillClass: 'border-red-500/40 bg-red-500/10 text-red-400',
            pulse: true,
        },
        paused: {
            label: 'PAUSED',
            dotClass: 'border border-zinc-500 bg-transparent',
            textClass: 'text-zinc-500',
            pillClass: 'border-zinc-800 text-zinc-500',
            pulse: false,
        },
    }
    return configs[props.status]
})

const ariaLabel = computed(() => {
    const labels = { up: 'Status: Online', down: 'Status: Offline', paused: 'Status: Paused' }
    return labels[props.status]
})
</script>

<template>
    <!-- Tag style (md) -->
    <span
        v-if="size === 'md'"
        :class="['inline-flex items-center gap-1.5 px-2 py-0.5 rounded border font-mono text-xs font-medium', config.pillClass]"
        :aria-label="ariaLabel"
        role="status"
    >
        <Icon v-if="status === 'up'" name="check" :size="12" />
        <span v-else-if="status === 'down'" class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse" />
        {{ config.label }}
    </span>

    <!-- Dot style (sm) -->
    <span v-else class="inline-flex items-center gap-1.5" :aria-label="ariaLabel" role="status">
        <span class="relative flex items-center justify-center w-2.5 h-2.5">
            <span v-if="config.pulse" class="absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75 animate-ping" />
            <Icon v-if="status === 'up' && showLabel" name="check" :size="12" class="text-zinc-400" />
            <span v-else :class="['relative w-2 h-2 rounded-full', config.dotClass]" />
        </span>
        <span v-if="showLabel" :class="['font-mono text-xs font-medium uppercase', config.textClass]">
            {{ config.label }}
        </span>
    </span>
</template>
