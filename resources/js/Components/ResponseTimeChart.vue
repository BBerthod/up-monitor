<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue'
import SegmentedControl from '@/Components/SegmentedControl.vue'

const props = defineProps<{
    chartData: Array<{
        date?: string
        avg_ms?: number
        min_ms?: number
        max_ms?: number
        uptime_percent?: number
        id?: number
        status?: 'up' | 'down'
        response_time_ms?: number
        status_code?: number
        checked_at?: string
    }>
    currentPeriod: string
    monitorId: number
}>()

const emit = defineEmits<{
    periodChange: [period: string]
}>()

const periods = ['6mo', '3mo', '1mo', '7d', '24h', '1h'] as const

const viewBoxWidth = 800
const viewBoxHeight = 300
const paddingLeft = 60
const paddingRight = 20
const paddingTop = 20
const paddingBottom = 40
const chartWidth = viewBoxWidth - paddingLeft - paddingRight
const chartHeight = viewBoxHeight - paddingTop - paddingBottom

interface NormalizedDataPoint {
    x: number
    y: number
    min_ms?: number
    max_ms?: number
    uptime_percent?: number
    date?: string
    status?: 'up' | 'down'
    status_code?: number
    checked_at?: string
    isAggregated: boolean
}

const normalizedData = computed<NormalizedDataPoint[]>(() => {
    if (!props.chartData || props.chartData.length === 0) return []

    const result: NormalizedDataPoint[] = []
    for (const item of props.chartData) {
        if (item.date !== undefined && item.avg_ms !== undefined) {
            result.push({
                x: new Date(item.date).getTime(),
                y: item.avg_ms,
                min_ms: item.min_ms,
                max_ms: item.max_ms,
                uptime_percent: item.uptime_percent,
                date: item.date,
                isAggregated: true,
            })
        } else if (item.checked_at !== undefined && item.response_time_ms !== undefined) {
            result.push({
                x: new Date(item.checked_at).getTime(),
                y: item.response_time_ms,
                status: item.status,
                status_code: item.status_code,
                checked_at: item.checked_at,
                isAggregated: false,
            })
        }
    }
    return result
})

const stats = computed(() => {
    if (normalizedData.value.length === 0) return { avg: 0, max: 0 }

    const values = normalizedData.value.map((d) => d.y)
    const avg = Math.round(values.reduce((a, b) => a + b, 0) / values.length)
    const max = Math.max(...values)

    return { avg, max }
})

const yTicks = computed(() => {
    if (normalizedData.value.length === 0) return [0, 100, 200, 300, 400]

    const maxVal = Math.max(...normalizedData.value.map((d) => d.y))
    if (maxVal === 0) return [0, 50, 100, 150, 200]

    const roughInterval = maxVal / 4
    const magnitude = Math.pow(10, Math.floor(Math.log10(roughInterval)))
    const normalized = roughInterval / magnitude

    let niceInterval: number
    if (normalized <= 1) niceInterval = magnitude
    else if (normalized <= 2) niceInterval = 2 * magnitude
    else if (normalized <= 5) niceInterval = 5 * magnitude
    else niceInterval = 10 * magnitude

    const ticks: number[] = []
    const tickCount = Math.ceil(maxVal / niceInterval)
    for (let i = 0; i <= tickCount; i++) {
        ticks.push(i * niceInterval)
    }

    return ticks.slice(0, 6)
})

const xLabels = computed(() => {
    if (normalizedData.value.length === 0) return []

    const timestamps = normalizedData.value.map((d) => d.x)
    const minTs = Math.min(...timestamps)
    const maxTs = Math.max(...timestamps)
    const labelCount = Math.min(7, normalizedData.value.length)
    const labels: { x: number; label: string }[] = []

    for (let i = 0; i < labelCount; i++) {
        const ratio = labelCount > 1 ? i / (labelCount - 1) : 0.5
        const ts = minTs + (maxTs - minTs) * ratio
        labels.push({
            x: paddingLeft + chartWidth * ratio,
            label: formatXLabel(new Date(ts)),
        })
    }

    return labels
})

const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

function formatXLabel(date: Date): string {
    switch (props.currentPeriod) {
        case '6mo':
        case '3mo':
            return `${months[date.getMonth()]} '${String(date.getFullYear()).slice(-2)}`
        case '1mo':
            return `${months[date.getMonth()]} ${date.getDate()}`
        case '7d':
            return `${days[date.getDay()]} ${date.getDate()}`
        case '24h':
        case '1h':
            return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
        default:
            return `${months[date.getMonth()]} ${date.getDate()}`
    }
}

function scaleY(value: number): number {
    const maxY = Math.max(...yTicks.value)
    if (maxY === 0) return paddingTop + chartHeight
    return paddingTop + chartHeight - (value / maxY) * chartHeight
}

function scaleX(timestamp: number): number {
    if (normalizedData.value.length === 0) return paddingLeft

    const timestamps = normalizedData.value.map((d) => d.x)
    const minTs = Math.min(...timestamps)
    const maxTs = Math.max(...timestamps)

    if (maxTs === minTs) return paddingLeft + chartWidth / 2
    return paddingLeft + ((timestamp - minTs) / (maxTs - minTs)) * chartWidth
}

const smoothPath = (points: { x: number; y: number }[]): string => {
    if (points.length === 0) return ''
    if (points.length === 1) return `M ${points[0].x.toFixed(2)},${points[0].y.toFixed(2)}`

    let d = `M ${points[0].x.toFixed(2)},${points[0].y.toFixed(2)}`

    for (let i = 1; i < points.length; i++) {
        const prev = points[i - 1]
        const curr = points[i]
        const cpx = (prev.x + curr.x) / 2
        d += ` C ${cpx.toFixed(2)},${prev.y.toFixed(2)} ${cpx.toFixed(2)},${curr.y.toFixed(2)} ${curr.x.toFixed(2)},${curr.y.toFixed(2)}`
    }

    return d
}

const pathD = computed(() => {
    if (normalizedData.value.length === 0) return ''

    const sortedData = [...normalizedData.value].sort((a, b) => a.x - b.x)
    const points = sortedData.map((d) => ({ x: scaleX(d.x), y: scaleY(d.y) }))

    return smoothPath(points)
})

const areaD = computed(() => {
    if (normalizedData.value.length === 0) return ''

    const sortedData = [...normalizedData.value].sort((a, b) => a.x - b.x)
    const bottomY = paddingTop + chartHeight
    const points = sortedData.map((d) => ({ x: scaleX(d.x), y: scaleY(d.y) }))

    const curvePath = smoothPath(points)
    const firstX = points[0].x.toFixed(2)
    const lastX = points[points.length - 1].x.toFixed(2)

    return `${curvePath} L ${lastX},${bottomY} L ${firstX},${bottomY} Z`
})

const tooltip = ref({
    show: false,
    x: 0,
    y: 0,
    svgX: 0,
    svgY: 0,
    data: null as NormalizedDataPoint | null,
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

    if (x + 220 > vw) x = clientX - 220
    if (y < 10) y = clientY + 20
    if (y + 160 > vh) y = vh - 170

    return { x, y }
}

function showTooltip(event: MouseEvent | TouchEvent, data: NormalizedDataPoint) {
    const pos = getTooltipPosition(event)
    tooltip.value = {
        show: true,
        x: pos.x,
        y: pos.y,
        svgX: scaleX(data.x),
        svgY: scaleY(data.y),
        data,
    }
}

function updateTooltipPosition(event: MouseEvent | TouchEvent) {
    const pos = getTooltipPosition(event)
    tooltip.value.x = pos.x
    tooltip.value.y = pos.y
}

function hideTooltip() {
    tooltip.value.show = false
    tooltip.value.data = null
}

function handleMouseEnter(event: MouseEvent, data: NormalizedDataPoint) {
    showTooltip(event, data)
}

function handleMouseMove(event: MouseEvent, data: NormalizedDataPoint) {
    if (tooltip.value.data?.x !== data.x) {
        showTooltip(event, data)
    }
    updateTooltipPosition(event)
}

function handleMouseLeave() {
    if (!isTouchDevice.value) {
        hideTooltip()
    }
}

function handleTouchStart(event: TouchEvent, data: NormalizedDataPoint) {
    event.preventDefault()
    if (touchTimeout.value) clearTimeout(touchTimeout.value)
    showTooltip(event, data)
}

function handleTouchMove(event: TouchEvent, data: NormalizedDataPoint) {
    event.preventDefault()
    if (touchTimeout.value) clearTimeout(touchTimeout.value)
    if (tooltip.value.data?.x !== data.x) {
        showTooltip(event, data)
    }
    updateTooltipPosition(event)
}

function handleTouchEnd() {
    touchTimeout.value = window.setTimeout(hideTooltip, 2000)
}

function formatTooltipDate(data: NormalizedDataPoint): string {
    const date = new Date(data.x)
    const month = months[date.getMonth()]
    const day = date.getDate()
    const year = date.getFullYear()

    if (data.isAggregated) return `${month} ${day}, ${year}`

    const hours = String(date.getHours()).padStart(2, '0')
    const minutes = String(date.getMinutes()).padStart(2, '0')
    return `${month} ${day}, ${year} ${hours}:${minutes}`
}

function getStatusColor(status?: 'up' | 'down'): string {
    if (status === 'up') return '#10b981'
    if (status === 'down') return '#ef4444'
    return '#10b981'
}
</script>

<template>
    <div class="glass p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <SegmentedControl :options="[...periods]" :model-value="currentPeriod" @update:model-value="emit('periodChange', $event)" />

            <div class="flex items-center gap-3">
                <div v-if="normalizedData.length > 0" class="text-sm text-slate-400">
                    Avg: {{ stats.avg }}ms · Max: {{ stats.max }}ms
                </div>
                <slot name="actions" />
            </div>
        </div>

        <div class="relative">
            <svg
                v-if="normalizedData.length > 0"
                ref="svgRef"
                viewBox="0 0 800 300"
                preserveAspectRatio="xMidYMid meet"
                class="w-full h-auto"
            >
                <defs>
                    <linearGradient id="areaGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#10b981" stop-opacity="0.3" />
                        <stop offset="100%" stop-color="#10b981" stop-opacity="0" />
                    </linearGradient>
                    <linearGradient id="verticalLineGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#10b981" stop-opacity="0.6" />
                        <stop offset="50%" stop-color="#10b981" stop-opacity="0.3" />
                        <stop offset="100%" stop-color="#10b981" stop-opacity="0" />
                    </linearGradient>
                </defs>

                <g>
                    <line
                        v-for="tick in yTicks"
                        :key="'grid-' + tick"
                        :x1="paddingLeft"
                        :y1="scaleY(tick)"
                        :x2="viewBoxWidth - paddingRight"
                        :y2="scaleY(tick)"
                        stroke="white"
                        stroke-opacity="0.05"
                        stroke-dasharray="4 4"
                    />
                </g>

                <g>
                    <text
                        v-for="tick in yTicks"
                        :key="'ytick-' + tick"
                        :x="paddingLeft - 10"
                        :y="scaleY(tick) + 4"
                        text-anchor="end"
                        style="font-size: 11px; fill: #64748b"
                    >
                        {{ tick }}ms
                    </text>
                </g>

                <g>
                    <text
                        v-for="(label, index) in xLabels"
                        :key="'xlabel-' + index"
                        :x="label.x"
                        :y="viewBoxHeight - 10"
                        text-anchor="middle"
                        style="font-size: 11px; fill: #64748b"
                    >
                        {{ label.label }}
                    </text>
                </g>

                <path :d="areaD" fill="url(#areaGradient)" />

                <path
                    :d="pathD"
                    stroke="#10b981"
                    stroke-width="2"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <circle
                    v-for="(point, index) in normalizedData.filter(p => p.status === 'down')"
                    :key="'down-' + index"
                    :cx="scaleX(point.x)"
                    :cy="scaleY(point.y)"
                    r="4"
                    fill="#ef4444"
                    stroke="#0f172a"
                    stroke-width="2"
                />

                <line
                    v-if="tooltip.show && tooltip.data"
                    :x1="tooltip.svgX"
                    :y1="paddingTop"
                    :x2="tooltip.svgX"
                    :y2="viewBoxHeight - paddingBottom"
                    stroke="url(#verticalLineGradient)"
                    stroke-width="1"
                    stroke-dasharray="4 2"
                    class="transition-opacity duration-150"
                />

                <circle
                    v-if="tooltip.show && tooltip.data"
                    :cx="tooltip.svgX"
                    :cy="tooltip.svgY"
                    r="6"
                    :fill="getStatusColor(tooltip.data.status)"
                    stroke="rgba(255,255,255,0.3)"
                    stroke-width="2"
                    class="transition-opacity duration-150"
                />

                <rect
                    v-for="(point, index) in normalizedData"
                    :key="'hit-' + index"
                    :x="scaleX(point.x) - Math.max(8, 800 / normalizedData.length / 2)"
                    :y="paddingTop"
                    :width="Math.max(16, 800 / normalizedData.length)"
                    :height="chartHeight"
                    fill="transparent"
                    class="cursor-pointer"
                    @mouseenter="handleMouseEnter($event, point)"
                    @mousemove="handleMouseMove($event, point)"
                    @mouseleave="handleMouseLeave"
                    @touchstart.passive="handleTouchStart($event, point)"
                    @touchmove.passive="handleTouchMove($event, point)"
                    @touchend="handleTouchEnd"
                />
            </svg>

            <div v-else class="flex items-center justify-center h-[300px] text-slate-500">
                No data available for this period
            </div>
        </div>
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
                v-if="tooltip.show && tooltip.data"
                class="fixed z-50 px-4 py-3 rounded-xl pointer-events-none shadow-2xl min-w-[180px]"
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
                    {{ formatTooltipDate(tooltip.data) }}
                </div>

                <div class="space-y-1.5">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-xs text-slate-500">Response</span>
                        <span class="text-sm font-semibold font-mono text-white">{{ Math.round(tooltip.data.y) }}<span class="text-slate-500 text-xs ml-0.5">ms</span></span>
                    </div>

                    <template v-if="tooltip.data.isAggregated">
                        <div v-if="tooltip.data.min_ms !== undefined" class="flex items-center justify-between gap-4">
                            <span class="text-xs text-slate-500">Min</span>
                            <span class="text-xs font-mono text-slate-300">{{ tooltip.data.min_ms }}ms</span>
                        </div>
                        <div v-if="tooltip.data.max_ms !== undefined" class="flex items-center justify-between gap-4">
                            <span class="text-xs text-slate-500">Max</span>
                            <span class="text-xs font-mono text-slate-300">{{ tooltip.data.max_ms }}ms</span>
                        </div>
                        <div v-if="tooltip.data.uptime_percent !== undefined" class="flex items-center justify-between gap-4">
                            <span class="text-xs text-slate-500">Uptime</span>
                            <span class="text-xs font-mono text-emerald-400">{{ Number(tooltip.data.uptime_percent).toFixed(1) }}%</span>
                        </div>
                    </template>

                    <template v-else>
                        <div v-if="tooltip.data.status_code" class="flex items-center justify-between gap-4">
                            <span class="text-xs text-slate-500">Status</span>
                            <span class="text-xs font-mono text-slate-300">{{ tooltip.data.status_code }}</span>
                        </div>
                        <div v-if="tooltip.data.status" class="flex items-center justify-between gap-4">
                            <span class="text-xs text-slate-500">State</span>
                            <span class="flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full" :class="tooltip.data.status === 'up' ? 'bg-emerald-400' : 'bg-red-400'"></span>
                                <span :class="tooltip.data.status === 'up' ? 'text-emerald-400' : 'text-red-400'" class="text-xs font-medium">
                                    {{ tooltip.data.status === 'up' ? 'Up' : 'Down' }}
                                </span>
                            </span>
                        </div>
                    </template>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
