<script setup lang="ts">
import { computed } from 'vue'

export type Severity = 'critical' | 'major' | 'minor' | 'warning'

const props = defineProps<{
    severity: Severity | string
}>()

const config = computed(() => {
    const configs: Record<string, { label: string; dotColor: string; pillClass: string }> = {
        critical: {
            label: 'Critical',
            dotColor: '#dc2626',
            pillClass: 'bg-red-600/15 text-red-400 border-red-600/30',
        },
        major: {
            label: 'Major',
            dotColor: '#f97316',
            pillClass: 'bg-orange-500/15 text-orange-400 border-orange-500/30',
        },
        minor: {
            label: 'Minor',
            dotColor: '#eab308',
            pillClass: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/30',
        },
        warning: {
            label: 'Warning',
            dotColor: '#6b7280',
            pillClass: 'bg-transparent text-zinc-400 border-zinc-800',
        },
    }
    return configs[props.severity] ?? {
        label: props.severity,
        dotColor: '#6b7280',
        pillClass: 'bg-transparent text-zinc-400 border-zinc-800',
    }
})
</script>

<template>
    <span
        :class="['inline-flex items-center gap-1.5 px-1.5 py-0.5 rounded text-xs font-medium border', config.pillClass]"
        :aria-label="`Severity: ${config.label}`"
    >
        <span
            class="w-1.5 h-1.5 rounded-full shrink-0"
            :style="{ backgroundColor: config.dotColor }"
        />
        {{ config.label }}
    </span>
</template>
