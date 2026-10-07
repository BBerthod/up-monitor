<script setup lang="ts">
import { computed, ref } from 'vue'

const props = withDefaults(defineProps<{
    data: Record<string, number>
    days?: number
}>(), {
    days: 365,
})

const cellSize = 12
const gap = 2

const tooltip = ref<{ show: boolean; x: number; y: number; text: string }>({ show: false, x: 0, y: 0, text: '' })

const weeks = computed(() => {
    const result: { date: string; day: number; ms: number | null }[][] = []
    const today = new Date()
    const start = new Date(today)
    start.setDate(start.getDate() - (props.days - 1))
    start.setDate(start.getDate() - start.getDay() + 1)

    let currentWeek: { date: string; day: number; ms: number | null }[] = []

    for (let d = new Date(start); d <= today; d.setDate(d.getDate() + 1)) {
        const key = d.toISOString().slice(0, 10)
        const dayOfWeek = (d.getDay() + 6) % 7

        if (dayOfWeek === 0 && currentWeek.length > 0) {
            result.push(currentWeek)
            currentWeek = []
        }

        currentWeek.push({ date: key, day: dayOfWeek, ms: props.data[key] ?? null })
    }
    if (currentWeek.length > 0) result.push(currentWeek)

    return result
})

const months = computed(() => {
    const labels: { label: string; col: number }[] = []
    let lastMonth = -1
    weeks.value.forEach((week, i) => {
        const d = new Date(week[0].date)
        const m = d.getMonth()
        if (m !== lastMonth) {
            labels.push({ label: d.toLocaleString('en', { month: 'short' }), col: i })
            lastMonth = m
        }
    })
    return labels
})

const cellColor = (ms: number | null): string => {
    if (ms === null) return 'fill-white/5'
    if (ms < 200) return 'fill-emerald-500'
    if (ms < 500) return 'fill-emerald-400'
    if (ms < 1000) return 'fill-yellow-500'
    if (ms < 2000) return 'fill-orange-500'
    return 'fill-red-500'
}

const showTooltip = (e: MouseEvent, cell: { date: string; ms: number | null }) => {
    const formatted = new Date(cell.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
    const rect = (e.target as SVGElement).getBoundingClientRect()
    const tooltipWidth = 180
    const tooltipHeight = 28
    const margin = 8
    const rawLeft = rect.left + 6
    const rawTop = rect.top - tooltipHeight - 4
    const clampedLeft = Math.min(Math.max(margin, rawLeft), window.innerWidth - tooltipWidth - margin)
    const clampedTop = Math.min(Math.max(margin, rawTop), window.innerHeight - tooltipHeight - margin)
    tooltip.value = {
        show: true,
        x: clampedLeft,
        y: clampedTop,
        text: cell.ms !== null ? `${formatted}: ${cell.ms}ms avg` : `${formatted}: No data`,
    }
}

const hideTooltip = () => { tooltip.value.show = false }

const svgWidth = computed(() => weeks.value.length * (cellSize + gap) + 30)
const svgHeight = computed(() => 7 * (cellSize + gap) + 24)
</script>

<template>
    <div class="relative">
        <svg
            :viewBox="`0 0 ${svgWidth} ${svgHeight}`"
            preserveAspectRatio="xMinYMid meet"
            class="w-full h-auto"
            :style="{ maxWidth: `${svgWidth * 2}px` }"
        >
            <text v-for="m in months" :key="m.col" :x="m.col * (cellSize + gap) + 30" y="10" class="fill-slate-500 text-[10px]">{{ m.label }}</text>
            <text y="30" x="0" class="fill-slate-500 text-[10px]">M</text>
            <text y="58" x="0" class="fill-slate-500 text-[10px]">W</text>
            <text y="86" x="0" class="fill-slate-500 text-[10px]">F</text>
            <template v-for="(week, wi) in weeks" :key="wi">
                <rect v-for="cell in week" :key="cell.date"
                    :x="wi * (cellSize + gap) + 30"
                    :y="cell.day * (cellSize + gap) + 18"
                    :width="cellSize" :height="cellSize" rx="2"
                    :class="cellColor(cell.ms)"
                    class="cursor-pointer transition-opacity hover:opacity-80"
                    @mouseenter="showTooltip($event, cell)"
                    @mouseleave="hideTooltip" />
            </template>
        </svg>
        <Teleport to="body">
            <div v-if="tooltip.show" class="fixed z-50 px-2 py-1 rounded text-xs text-white bg-slate-800 border border-white/10 pointer-events-none whitespace-nowrap"
                :style="{ left: `${tooltip.x}px`, top: `${tooltip.y}px` }">
                {{ tooltip.text }}
            </div>
        </Teleport>
        <div class="flex items-center gap-2 mt-3 text-xs text-slate-500">
            <span>Less</span>
            <div class="w-3 h-3 rounded-sm bg-white/5" />
            <div class="w-3 h-3 rounded-sm bg-emerald-500" />
            <div class="w-3 h-3 rounded-sm bg-yellow-500" />
            <div class="w-3 h-3 rounded-sm bg-orange-500" />
            <div class="w-3 h-3 rounded-sm bg-red-500" />
            <span>More</span>
        </div>
    </div>
</template>
