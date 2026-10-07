<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
    target: number
    current: number
}>()

// The gauge only needs to distinguish "close to 100%" outcomes, so it uses a
// useful 99–100% scale instead of 0–100% (where every real SLA value would
// sit in the last sliver of the bar and the gauge would say nothing).
const SCALE_MIN = 99

const meetsTarget = computed(() => props.current >= props.target)
const delta = computed(() => props.current - props.target)

const scalePercent = (value: number): number => {
    const span = 100 - SCALE_MIN
    return Math.max(0, Math.min(100, ((value - SCALE_MIN) / span) * 100))
}

const fillPercent = computed(() => scalePercent(props.current))
const targetPercent = computed(() => scalePercent(props.target))

// Colour marks a problem only — meeting the target is calm, not celebratory.
const barColorClass = computed(() => (meetsTarget.value ? 'bg-[var(--color-text-faint)]' : 'bg-[var(--color-danger)]'))
const deltaColorClass = computed(() => (meetsTarget.value ? 'text-[var(--color-text-secondary)]' : 'text-[var(--color-danger)]'))

const deltaLabel = computed(() => {
    const abs = Math.abs(delta.value).toFixed(2)
    return meetsTarget.value ? `${abs} pt above target` : `${abs} pt below target`
})
</script>

<template>
    <div class="glass p-4 space-y-2.5">
        <p class="text-sm">
            <span class="font-mono font-semibold text-[var(--color-text-primary)]">{{ current.toFixed(2) }}%</span>
            <span class="text-[var(--color-text-muted)]"> this month · target </span>
            <span class="font-mono text-[var(--color-text-secondary)]">{{ target.toFixed(2) }}%</span>
            <span class="text-[var(--color-text-muted)]"> · </span>
            <span class="font-mono" :class="deltaColorClass">{{ deltaLabel }}</span>
        </p>

        <div class="relative w-full h-1.5 rounded-full bg-[var(--color-surface-2)] overflow-hidden">
            <div
                class="h-full rounded-full transition-all duration-500"
                :class="barColorClass"
                :style="{ width: `${fillPercent}%` }"
                role="progressbar"
                :aria-valuenow="current"
                :aria-valuemin="SCALE_MIN"
                :aria-valuemax="100"
                :aria-label="`SLA: ${current.toFixed(2)}% of ${target.toFixed(2)}% target`"
            />
            <!-- Target marker -->
            <div
                class="absolute top-0 bottom-0 w-px bg-[var(--color-text-faint)]"
                :style="{ left: `${targetPercent}%` }"
            />
        </div>

        <div class="flex items-center justify-between text-[10px] font-mono text-[var(--color-text-faint)]">
            <span>{{ SCALE_MIN }}%</span>
            <span>100%</span>
        </div>
    </div>
</template>
