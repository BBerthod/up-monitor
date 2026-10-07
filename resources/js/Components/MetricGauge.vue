<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
    label: string
    percent: number
    warning: number
    critical: number
    detail?: string
}>()

// Console rule: colour signals a problem, not a state. A calm resource
// usage below the warning threshold stays neutral grey — it is not an
// achievement worth an emerald highlight.
const colorVariant = computed(() => {
    if (props.percent >= props.critical) return 'red'
    if (props.percent >= props.warning) return 'amber'
    return 'neutral'
})

const barClass = computed(() => ({
    red: 'bg-red-500',
    amber: 'bg-amber-400',
    neutral: 'bg-zinc-500',
}[colorVariant.value]))

const trackClass = computed(() => ({
    red: 'bg-red-500/20',
    amber: 'bg-amber-400/20',
    neutral: 'bg-zinc-500/20',
}[colorVariant.value]))

const textClass = computed(() => ({
    red: 'text-red-400',
    amber: 'text-amber-400',
    neutral: 'text-zinc-400',
}[colorVariant.value]))

// Cap fill at 100% for display; values above 100 show as full bar
const fillPercent = computed(() => Math.min(props.percent, 100))
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-center justify-between text-xs">
            <span class="text-zinc-400 font-medium">{{ label }}</span>
            <div class="flex items-center gap-2">
                <span v-if="detail" class="text-zinc-600 font-mono">{{ detail }}</span>
                <span :class="['font-mono font-semibold', textClass]">{{ percent.toFixed(1) }}%</span>
            </div>
        </div>
        <div class="relative w-full h-1.5 rounded-full overflow-hidden" :class="trackClass">
            <div
                class="h-full rounded-full transition-all duration-500"
                :class="barClass"
                :style="{ width: `${fillPercent}%` }"
                role="progressbar"
                :aria-valuenow="percent"
                :aria-valuemin="0"
                :aria-valuemax="100"
                :aria-label="`${label}: ${percent.toFixed(1)}%`"
            />
        </div>
    </div>
</template>
