<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue'

interface ChartPoint {
    date: string
    hit_ratio: number
    avg_ms: number
}

const props = defineProps<{
    chartData: ChartPoint[]
}>()

const viewBoxWidth = 800
const viewBoxHeight = 200
const paddingLeft = 50
const paddingRight = 20
const paddingTop = 10
const paddingBottom = 30
const chartWidth = viewBoxWidth - paddingLeft - paddingRight
const chartHeight = viewBoxHeight - paddingTop - paddingBottom

const gridLines = [0, 25, 50, 75, 100]

const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

interface ScaledPoint extends ChartPoint {
    x: number
    y: number
}

const points = computed<ScaledPoint[]>(() => {
    if (props.chartData.length === 0) return []

    return props.chartData.map((d, i) => {
        const x = paddingLeft + (i / Math.max(props.chartData.length - 1, 1)) * chartWidth
        const y = paddingTop + chartHeight - (d.hit_ratio / 100) * chartHeight
        return { ...d, x, y }
    })
})

const smoothPath = (pts: { x: number; y: number }[]): string => {
    if (pts.length === 0) return ''
    if (pts.length === 1) return `M ${pts[0].x.toFixed(2)},${pts[0].y.toFixed(2)}`

    let d = `M ${pts[0].x.toFixed(2)},${pts[0].y.toFixed(2)}`

    for (let i = 1; i < pts.length; i++) {
        const prev = pts[i - 1]
        const curr = pts[i]
        const cpx = (prev.x + curr.x) / 2
        d += ` C ${cpx.toFixed(2)},${prev.y.toFixed(2)} ${cpx.toFixed(2)},${curr.y.toFixed(2)} ${curr.x.toFixed(2)},${curr.y.toFixed(2)}`
    }

    return d
}

const linePath = computed(() => {
    if (points.value.length === 0) return ''
    return smoothPath(points.value.map((p) => ({ x: p.x, y: p.y })))
})

const areaPath = computed(() => {
    if (points.value.length === 0) return ''
    const base = paddingTop + chartHeight
    const first = points.value[0]
    const last = points.value[points.value.length - 1]
    return `${linePath.value} L ${last.x.toFixed(2)},${base} L ${first.x.toFixed(2)},${base} Z`
})

const xLabels = computed(() => {
    if (props.chartData.length <= 1) return []

    const step = Math.max(1, Math.floor(props.chartData.length / 5))
    const indices: number[] = []

    for (let i = 0; i < props.chartData.length; i += step) {
        indices.push(i)
    }
    // Always include the last point
    if (indices[indices.length - 1] !== props.chartData.length - 1) {
        indices.push(props.chartData.length - 1)
    }

    return indices.map((idx) => {
        const x = paddingLeft + (idx / Math.max(props.chartData.length - 1, 1)) * chartWidth
        const date = new Date(props.chartData[idx].date)
        const label = `${months[date.getMonth()]} ${date.getDate()}`
        return { x, label }
    })
})

const tooltip = ref({
    show: false,
    x: 0,
    y: 0,
    point: null as ScaledPoint | null,
})

const svgRef = ref<SVGSVGElement | null>(null)
const isTouchDevice = ref(false)
const touchTimeout = ref<number | null>(null)

onMounted(() => {
    isTouchDevice.value = 'ontouchstart' in window || navigator.maxTouchPoints > 0
    document.addEventListener('click', handleDocumentClick)
})

onUnmounted(() => {
    document.removeEventListener('click', handleDocumentClick)
    if (touchTimeout.value) clearTimeout(touchTimeout.value)
})

function handleDocumentClick(event: MouseEvent | TouchEvent) {
    if (tooltip.value.show && svgRef.value && !svgRef.value.contains(event.target as Node)) {
        hideTooltip()
    }
}

function getTooltipPosition(event: MouseEvent | TouchEvent) {
    const clientX = 'touches' in event ? event.touches[0].clientX : event.clientX
    const clientY = 'touches' in event ? event.touches[0].clientY : event.clientY

    const vw = window.innerWidth
    const vh = window.innerHeight

    let x = clientX + 16
    let y = clientY - 20

    if (x + 200 > vw) x = clientX - 200
    if (y < 10) y = clientY + 20
    if (y + 100 > vh) y = vh - 110

    return { x, y }
}

function showTooltipAt(event: MouseEvent | TouchEvent, point: ScaledPoint) {
    const pos = getTooltipPosition(event)
    tooltip.value = { show: true, x: pos.x, y: pos.y, point }
}

function updateTooltipPosition(event: MouseEvent | TouchEvent) {
    const pos = getTooltipPosition(event)
    tooltip.value.x = pos.x
    tooltip.value.y = pos.y
}

function hideTooltip() {
    tooltip.value.show = false
    tooltip.value.point = null
}

function handleMouseEnter(event: MouseEvent, point: ScaledPoint) {
    showTooltipAt(event, point)
}

function handleMouseMove(event: MouseEvent, point: ScaledPoint) {
    if (tooltip.value.point?.date !== point.date) {
        showTooltipAt(event, point)
    }
    updateTooltipPosition(event)
}

function handleMouseLeave() {
    if (!isTouchDevice.value) {
        hideTooltip()
    }
}

function handleTouchStart(event: TouchEvent, point: ScaledPoint) {
    event.preventDefault()
    if (touchTimeout.value) clearTimeout(touchTimeout.value)
    showTooltipAt(event, point)
}

function handleTouchMove(event: TouchEvent, point: ScaledPoint) {
    event.preventDefault()
    if (touchTimeout.value) clearTimeout(touchTimeout.value)
    if (tooltip.value.point?.date !== point.date) {
        showTooltipAt(event, point)
    }
    updateTooltipPosition(event)
}

function handleTouchEnd() {
    touchTimeout.value = window.setTimeout(hideTooltip, 2000)
}

function formatTooltipDate(d: string): string {
    const date = new Date(d)
    return `${months[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

function hitRatioColor(ratio: number): string {
    if (ratio >= 80) return '#10b981'
    if (ratio >= 50) return '#f59e0b'
    return '#ef4444'
}
</script>

<template>
    <div class="relative">
        <div
            v-if="chartData.length === 0"
            class="flex items-center justify-center h-[200px] text-slate-500 text-sm"
        >
            No warming data yet
        </div>

        <svg
            v-else
            ref="svgRef"
            :viewBox="`0 0 ${viewBoxWidth} ${viewBoxHeight}`"
            preserveAspectRatio="xMidYMid meet"
            class="w-full h-auto"
        >
            <defs>
                <linearGradient id="hitRatioGradient" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#10b981" stop-opacity="0.2" />
                    <stop offset="100%" stop-color="#10b981" stop-opacity="0" />
                </linearGradient>
                <linearGradient id="hitRatioLineGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                    <stop offset="0%" stop-color="#10b981" stop-opacity="0.6" />
                    <stop offset="50%" stop-color="#10b981" stop-opacity="0.3" />
                    <stop offset="100%" stop-color="#10b981" stop-opacity="0" />
                </linearGradient>
            </defs>

            <!-- Horizontal grid lines + Y-axis labels -->
            <g v-for="val in gridLines" :key="'grid-' + val">
                <line
                    :x1="paddingLeft"
                    :y1="paddingTop + chartHeight - (val / 100) * chartHeight"
                    :x2="viewBoxWidth - paddingRight"
                    :y2="paddingTop + chartHeight - (val / 100) * chartHeight"
                    stroke="white"
                    stroke-opacity="0.05"
                    stroke-dasharray="4 4"
                />
                <text
                    :x="paddingLeft - 8"
                    :y="paddingTop + chartHeight - (val / 100) * chartHeight + 4"
                    text-anchor="end"
                    style="font-size: 11px; fill: #64748b"
                >{{ val }}%</text>
            </g>

            <!-- X-axis labels -->
            <text
                v-for="(label, index) in xLabels"
                :key="'xlabel-' + index"
                :x="label.x"
                :y="viewBoxHeight - 5"
                text-anchor="middle"
                style="font-size: 11px; fill: #64748b"
            >{{ label.label }}</text>

            <!-- Area fill -->
            <path :d="areaPath" fill="url(#hitRatioGradient)" />

            <!-- Line -->
            <path
                :d="linePath"
                fill="none"
                stroke="#10b981"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />

            <!-- Vertical indicator line (when hovering) -->
            <line
                v-if="tooltip.show && tooltip.point"
                :x1="tooltip.point.x"
                :y1="paddingTop"
                :x2="tooltip.point.x"
                :y2="paddingTop + chartHeight"
                stroke="url(#hitRatioLineGradient)"
                stroke-width="1"
                stroke-dasharray="4 2"
            />

            <!-- Active data point circle (when hovering) -->
            <circle
                v-if="tooltip.show && tooltip.point"
                :cx="tooltip.point.x"
                :cy="tooltip.point.y"
                r="5"
                fill="#10b981"
                stroke="rgba(255,255,255,0.3)"
                stroke-width="2"
            />

            <!-- Transparent hit areas for hover/touch -->
            <rect
                v-for="(p, i) in points"
                :key="'hit-' + i"
                :x="p.x - Math.max(8, viewBoxWidth / points.length / 2)"
                :y="paddingTop"
                :width="Math.max(16, viewBoxWidth / points.length)"
                :height="chartHeight"
                fill="transparent"
                class="cursor-pointer"
                @mouseenter="handleMouseEnter($event, p)"
                @mousemove="handleMouseMove($event, p)"
                @mouseleave="handleMouseLeave"
                @touchstart.passive="handleTouchStart($event, p)"
                @touchmove.passive="handleTouchMove($event, p)"
                @touchend="handleTouchEnd"
            />
        </svg>
    </div>

    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="tooltip.show && tooltip.point"
                class="fixed z-50 px-4 py-3 rounded-xl pointer-events-none shadow-2xl min-w-[160px]"
                :style="{
                    left: `${tooltip.x}px`,
                    top: `${tooltip.y}px`,
                    background: 'linear-gradient(135deg, rgba(26, 26, 29, 0.95) 0%, rgba(17, 17, 19, 0.98) 100%)',
                    backdropFilter: 'blur(12px)',
                    border: '1px solid rgba(255, 255, 255, 0.1)',
                    boxShadow: '0 8px 32px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.05)',
                }"
            >
                <div class="text-xs font-medium text-slate-400 mb-2 tracking-wide uppercase">
                    {{ formatTooltipDate(tooltip.point.date) }}
                </div>
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-xs text-slate-500">Hit ratio</span>
                        <span
                            class="text-sm font-semibold font-mono"
                            :style="{ color: hitRatioColor(tooltip.point.hit_ratio) }"
                        >{{ tooltip.point.hit_ratio }}%</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-xs text-slate-500">Avg response</span>
                        <span class="text-sm font-semibold font-mono text-white">
                            {{ tooltip.point.avg_ms }}<span class="text-slate-500 text-xs ml-0.5">ms</span>
                        </span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
