<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
    /** Array of 0–100 values (percentages) ordered oldest→newest */
    points: number[]
    color?: string
    label?: string
}>()

const viewBoxWidth = 120
const viewBoxHeight = 28
const padX = 1
const padY = 2
const chartW = viewBoxWidth - padX * 2
const chartH = viewBoxHeight - padY * 2

const scaledPoints = computed(() => {
    if (props.points.length === 0) return []
    if (props.points.length === 1) {
        return [{ x: padX + chartW / 2, y: padY + chartH / 2 }]
    }
    const n = props.points.length
    return props.points.map((v, i) => ({
        x: padX + (i / (n - 1)) * chartW,
        // invert Y: 0% at bottom, 100% at top
        y: padY + chartH - (Math.min(Math.max(v, 0), 100) / 100) * chartH,
    }))
})

const polylinePoints = computed(() =>
    scaledPoints.value.map((p) => `${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' ')
)

// Flat line at midpoint when no data
const flatLine = computed(() =>
    `${padX},${padY + chartH / 2} ${padX + chartW},${padY + chartH / 2}`
)

const stroke = computed(() => props.color ?? '#10b981')
</script>

<template>
    <div class="flex flex-col gap-0.5">
        <span v-if="label" class="text-[10px] text-zinc-600 uppercase tracking-wider">{{ label }}</span>
        <svg
            :viewBox="`0 0 ${viewBoxWidth} ${viewBoxHeight}`"
            preserveAspectRatio="none"
            class="w-full"
            :style="{ height: `${viewBoxHeight}px` }"
            aria-hidden="true"
        >
            <polyline
                v-if="points.length > 0"
                :points="polylinePoints"
                fill="none"
                :stroke="stroke"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                opacity="0.85"
            />
            <!-- Flat placeholder when no data -->
            <polyline
                v-else
                :points="flatLine"
                fill="none"
                stroke="rgba(255,255,255,0.08)"
                stroke-width="1"
                stroke-dasharray="3 3"
            />
        </svg>
    </div>
</template>
