<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import SegmentedControl from '@/Components/SegmentedControl.vue'

const props = defineProps<{
    history: Array<{
        performance: number
        accessibility: number
        best_practices: number
        seo: number
        lcp: number | null
        fcp: number | null
        cls: number | null
        tbt: number | null
        speed_index: number | null
        scored_at: string
    }> | null
    monitorId: number
}>()

const currentPeriod = ref('30d')

const setPeriod = (period: string) => {
    currentPeriod.value = period
    router.visit(route('monitors.show', props.monitorId) + '?lh_period=' + period, {
        only: ['lighthouseHistory'],
        preserveState: true,
        preserveScroll: true,
    })
}

const viewBoxWidth = 800
const viewBoxHeight = 300
const padding = { left: 60, right: 20, top: 20, bottom: 40 }
const chartWidth = viewBoxWidth - padding.left - padding.right
const chartHeight = viewBoxHeight - padding.top - padding.bottom

const chartData = computed(() => {
    if (!props.history || props.history.length === 0) return []
    return [...props.history].sort((a, b) =>
        new Date(a.scored_at).getTime() - new Date(b.scored_at).getTime()
    )
})

const gridLines = [0, 25, 50, 75, 100]

const getY = (value: number) => padding.top + chartHeight - (value / 100) * chartHeight
const getX = (index: number) => {
    if (chartData.value.length <= 1) return padding.left + chartWidth / 2
    return padding.left + (index / (chartData.value.length - 1)) * chartWidth
}

const generatePath = (key: 'performance' | 'accessibility' | 'best_practices' | 'seo') => {
    if (chartData.value.length === 0) return ''
    return chartData.value.map((point, i) => {
        return `${i === 0 ? 'M' : 'L'} ${getX(i)} ${getY(point[key])}`
    }).join(' ')
}

const formatXLabel = (dateStr: string) => {
    const date = new Date(dateStr)
    if (currentPeriod.value === '90d') {
        const month = date.toLocaleDateString('en-US', { month: 'short' })
        const year = date.getFullYear().toString().slice(-2)
        return `${month} '${year}`
    }
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const xLabelIndices = computed(() => {
    const len = chartData.value.length
    if (len === 0) return []
    if (len <= 7) return chartData.value.map((_, i) => i)
    const step = Math.ceil(len / 7)
    const indices: number[] = []
    for (let i = 0; i < len; i += step) indices.push(i)
    if (indices[indices.length - 1] !== len - 1) indices.push(len - 1)
    return indices
})

const lineColors = {
    performance: '#10b981',
    accessibility: '#8b5cf6',
    best_practices: '#f59e0b',
    seo: '#10b981',
}

const tooltipData = ref<{
    x: number; y: number; date: string
    performance: number; accessibility: number; best_practices: number; seo: number
    lcp: number | null; fcp: number | null; cls: number | null; tbt: number | null; speed_index: number | null
} | null>(null)

const showTooltip = (event: MouseEvent, index: number) => {
    const point = chartData.value[index]
    if (!point) return
    tooltipData.value = { x: event.clientX, y: event.clientY, ...point, date: point.scored_at }
}
const hideTooltip = () => { tooltipData.value = null }

const formatTooltipDate = (dateStr: string) => new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })

const formatVital = (value: number | null, unit: string = '', decimals: number = 0) => {
    if (value === null) return '—'
    if (decimals > 0) return value.toFixed(decimals) + unit
    return Math.round(value) + unit
}

const currentPage = ref(1)
const itemsPerPage = 10
const totalPages = computed(() => props.history ? Math.ceil(props.history.length / itemsPerPage) : 0)
const paginatedHistory = computed(() => {
    if (!props.history) return []
    const start = (currentPage.value - 1) * itemsPerPage
    return props.history.slice(start, start + itemsPerPage)
})

const getScoreColor = (score: number) => score >= 90 ? 'text-emerald-400' : score >= 50 ? 'text-amber-400' : 'text-red-400'

const formatTableDate = (dateStr: string) => new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h3 class="section-label">Lighthouse History</h3>
            <SegmentedControl :options="['7d', '30d', '90d']" :model-value="currentPeriod" @update:model-value="setPeriod" />
        </div>

        <div v-if="!history || history.length === 0" class="text-center py-12 text-slate-400">
            No Lighthouse history yet
        </div>

        <div v-else class="space-y-4">
            <div class="bg-white/5 border border-white/10 rounded-lg p-4">
                <svg :viewBox="`0 0 ${viewBoxWidth} ${viewBoxHeight}`" class="w-full h-auto" preserveAspectRatio="xMidYMid meet">
                    <line v-for="y in gridLines" :key="'g-' + y" :x1="padding.left" :x2="viewBoxWidth - padding.right" :y1="getY(y)" :y2="getY(y)" stroke="rgba(255,255,255,0.1)" stroke-dasharray="4,4" />

                    <text v-for="y in gridLines" :key="'yt-' + y" :x="padding.left - 10" :y="getY(y) + 4" text-anchor="end" style="font-size: 11px; fill: #64748b">{{ y }}</text>

                    <text v-for="i in xLabelIndices" :key="'xl-' + i" :x="getX(i)" :y="viewBoxHeight - 10" text-anchor="middle" style="font-size: 11px; fill: #64748b">{{ formatXLabel(chartData[i].scored_at) }}</text>

                    <path :d="generatePath('performance')" fill="none" :stroke="lineColors.performance" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    <path :d="generatePath('accessibility')" fill="none" :stroke="lineColors.accessibility" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    <path :d="generatePath('best_practices')" fill="none" :stroke="lineColors.best_practices" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    <path :d="generatePath('seo')" fill="none" :stroke="lineColors.seo" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />

                    <g v-for="(point, i) in chartData" :key="'pt-' + i">
                        <circle :cx="getX(i)" :cy="getY(point.performance)" r="3" :fill="lineColors.performance" class="cursor-pointer" @mouseenter="showTooltip($event, i)" @mouseleave="hideTooltip" />
                        <circle :cx="getX(i)" :cy="getY(point.accessibility)" r="3" :fill="lineColors.accessibility" class="cursor-pointer" @mouseenter="showTooltip($event, i)" @mouseleave="hideTooltip" />
                        <circle :cx="getX(i)" :cy="getY(point.best_practices)" r="3" :fill="lineColors.best_practices" class="cursor-pointer" @mouseenter="showTooltip($event, i)" @mouseleave="hideTooltip" />
                        <circle :cx="getX(i)" :cy="getY(point.seo)" r="3" :fill="lineColors.seo" class="cursor-pointer" @mouseenter="showTooltip($event, i)" @mouseleave="hideTooltip" />
                    </g>
                </svg>
            </div>

            <div class="flex flex-wrap gap-6 justify-center text-sm">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full" :style="{ backgroundColor: lineColors.performance }"></span>
                    <span class="text-slate-300">Performance</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full" :style="{ backgroundColor: lineColors.accessibility }"></span>
                    <span class="text-slate-300">Accessibility</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full" :style="{ backgroundColor: lineColors.best_practices }"></span>
                    <span class="text-slate-300">Best Practices</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full" :style="{ backgroundColor: lineColors.seo }"></span>
                    <span class="text-slate-300">SEO</span>
                </div>
            </div>

            <div class="bg-white/5 border border-white/10 rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="text-left px-4 py-3 text-slate-400 font-medium">Date</th>
                                <th class="text-center px-4 py-3 text-slate-400 font-medium">Perf</th>
                                <th class="text-center px-4 py-3 text-slate-400 font-medium">A11y</th>
                                <th class="text-center px-4 py-3 text-slate-400 font-medium">BP</th>
                                <th class="text-center px-4 py-3 text-slate-400 font-medium">SEO</th>
                                <th class="text-center px-4 py-3 text-slate-400 font-medium">LCP</th>
                                <th class="text-center px-4 py-3 text-slate-400 font-medium">FCP</th>
                                <th class="text-center px-4 py-3 text-slate-400 font-medium">CLS</th>
                                <th class="text-center px-4 py-3 text-slate-400 font-medium">TBT</th>
                                <th class="text-center px-4 py-3 text-slate-400 font-medium">SI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in paginatedHistory" :key="i" class="border-b border-white/5 hover:bg-white/5 transition-colors">
                                <td class="px-4 py-3 text-slate-300 whitespace-nowrap">{{ formatTableDate(row.scored_at) }}</td>
                                <td class="px-4 py-3 text-center font-mono" :class="getScoreColor(row.performance)">{{ row.performance }}</td>
                                <td class="px-4 py-3 text-center font-mono" :class="getScoreColor(row.accessibility)">{{ row.accessibility }}</td>
                                <td class="px-4 py-3 text-center font-mono" :class="getScoreColor(row.best_practices)">{{ row.best_practices }}</td>
                                <td class="px-4 py-3 text-center font-mono" :class="getScoreColor(row.seo)">{{ row.seo }}</td>
                                <td class="px-4 py-3 text-center font-mono text-slate-300">{{ formatVital(row.lcp, 'ms') }}</td>
                                <td class="px-4 py-3 text-center font-mono text-slate-300">{{ formatVital(row.fcp, 'ms') }}</td>
                                <td class="px-4 py-3 text-center font-mono text-slate-300">{{ formatVital(row.cls, '', 4) }}</td>
                                <td class="px-4 py-3 text-center font-mono text-slate-300">{{ formatVital(row.tbt, 'ms') }}</td>
                                <td class="px-4 py-3 text-center font-mono text-slate-300">{{ formatVital(row.speed_index, 'ms') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="totalPages > 1" class="flex items-center justify-between px-4 py-3 border-t border-white/10">
                    <button @click="currentPage--" :disabled="currentPage === 1"
                        class="px-3 py-1.5 text-sm font-medium rounded border transition-colors"
                        :class="currentPage === 1 ? 'bg-white/5 text-slate-500 border-white/5 cursor-not-allowed' : 'bg-white/5 text-slate-400 border-white/10 hover:bg-white/10 hover:text-white'">
                        Prev
                    </button>
                    <span class="text-sm text-slate-400">Page {{ currentPage }} of {{ totalPages }}</span>
                    <button @click="currentPage++" :disabled="currentPage === totalPages"
                        class="px-3 py-1.5 text-sm font-medium rounded border transition-colors"
                        :class="currentPage === totalPages ? 'bg-white/5 text-slate-500 border-white/5 cursor-not-allowed' : 'bg-white/5 text-slate-400 border-white/10 hover:bg-white/10 hover:text-white'">
                        Next
                    </button>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="tooltipData"
                class="fixed z-50 bg-slate-800 border border-white/10 rounded-lg px-3 py-2 text-xs shadow-xl pointer-events-none"
                :style="{ left: tooltipData.x + 15 + 'px', top: tooltipData.y + 15 + 'px' }">
                <div class="text-slate-300 mb-2 font-medium">{{ formatTooltipDate(tooltipData.date) }}</div>
                <div class="space-y-1">
                    <div class="flex justify-between gap-4"><span :style="{ color: lineColors.performance }">Performance</span><span class="font-mono text-white">{{ tooltipData.performance }}</span></div>
                    <div class="flex justify-between gap-4"><span :style="{ color: lineColors.accessibility }">Accessibility</span><span class="font-mono text-white">{{ tooltipData.accessibility }}</span></div>
                    <div class="flex justify-between gap-4"><span :style="{ color: lineColors.best_practices }">Best Practices</span><span class="font-mono text-white">{{ tooltipData.best_practices }}</span></div>
                    <div class="flex justify-between gap-4"><span :style="{ color: lineColors.seo }">SEO</span><span class="font-mono text-white">{{ tooltipData.seo }}</span></div>
                </div>
                <div class="border-t border-white/10 mt-2 pt-2 space-y-1">
                    <div class="flex justify-between gap-4"><span class="text-slate-400">LCP</span><span class="font-mono text-slate-300">{{ formatVital(tooltipData.lcp, 'ms') }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-slate-400">FCP</span><span class="font-mono text-slate-300">{{ formatVital(tooltipData.fcp, 'ms') }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-slate-400">CLS</span><span class="font-mono text-slate-300">{{ formatVital(tooltipData.cls, '', 4) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-slate-400">TBT</span><span class="font-mono text-slate-300">{{ formatVital(tooltipData.tbt, 'ms') }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-slate-400">Speed Index</span><span class="font-mono text-slate-300">{{ formatVital(tooltipData.speed_index, 'ms') }}</span></div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
