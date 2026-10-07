<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const props = defineProps<{
    scores: {
        performance: number
        accessibility: number
        best_practices: number
        seo: number
        scored_at: string
        lcp: number | null
        fcp: number | null
        cls: number | null
        tbt: number | null
        speed_index: number | null
    } | null
    monitorId: number
    monitorType: string
}>()

const auditing = ref(false)
const auditMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null)
const auditTimeout = ref<ReturnType<typeof setTimeout> | null>(null)

watch(() => props.scores?.scored_at, (newVal, oldVal) => {
    if (auditing.value && newVal && newVal !== oldVal) {
        if (auditTimeout.value) clearTimeout(auditTimeout.value)
        auditing.value = false
        auditMessage.value = { type: 'success', text: 'Lighthouse audit completed!' }
        setTimeout(() => { auditMessage.value = null }, 5000)
    }
})
const categories = computed(() => {
    if (!props.scores) return []
    return [
        { label: 'Performance', value: props.scores.performance },
        { label: 'Accessibility', value: props.scores.accessibility },
        { label: 'Best Practices', value: props.scores.best_practices },
        { label: 'SEO', value: props.scores.seo },
    ]
})

const webVitals = computed(() => {
    if (!props.scores) return []
    return [
        { label: 'LCP', value: props.scores.lcp, unit: 'ms', thresholds: { good: 2500, poor: 4000 } },
        { label: 'FCP', value: props.scores.fcp, unit: 'ms', thresholds: { good: 1800, poor: 3000 } },
        { label: 'CLS', value: props.scores.cls, unit: '', thresholds: { good: 0.1, poor: 0.25 } },
        { label: 'TBT', value: props.scores.tbt, unit: 'ms', thresholds: { good: 200, poor: 600 } },
        { label: 'Speed Index', value: props.scores.speed_index, unit: 'ms', thresholds: { good: 3400, poor: 5800 } },
    ]
})

const scoreColor = (v: number) => v >= 90 ? '#0cce6b' : v >= 50 ? '#ffa400' : '#ff4e42'

const vitalColor = (value: number | null, thresholds: { good: number; poor: number }) => {
    if (value === null) return '#64748b'
    if (value < thresholds.good) return '#0cce6b'
    if (value < thresholds.poor) return '#ffa400'
    return '#ff4e42'
}

const formatValue = (value: number | null, unit: string) => {
    if (value === null) return '—'
    if (unit === '') return value.toFixed(4)
    return `${Math.round(value)}${unit}`
}

const circumference = 2 * Math.PI * 40
const dashOffset = (v: number) => circumference - (v / 100) * circumference

const formatDate = (d: string) => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })

const runAudit = () => {
    auditing.value = true
    auditMessage.value = null
    if (auditTimeout.value) clearTimeout(auditTimeout.value)
    auditTimeout.value = setTimeout(() => {
        if (auditing.value) {
            auditing.value = false
            auditMessage.value = { type: 'error', text: 'Audit timed out. It may still be running — refresh the page later.' }
            setTimeout(() => { auditMessage.value = null }, 8000)
        }
    }, 120_000)
    router.post(route('monitors.lighthouse', props.monitorId), {}, {
        preserveScroll: true,
        onFinish: () => {
            const flash = (usePage().props as any).flash
            if (flash?.error) {
                if (auditTimeout.value) clearTimeout(auditTimeout.value)
                auditing.value = false
                auditMessage.value = { type: 'error', text: flash.error }
                setTimeout(() => { auditMessage.value = null }, 5000)
            }
        },
    })
}
</script>

<template>
    <div v-if="scores" class="space-y-4">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
            <div v-for="cat in categories" :key="cat.label" class="flex flex-col items-center">
                <div class="relative w-24 h-24">
                    <svg viewBox="0 0 100 100" class="w-full h-full -rotate-90">
                        <circle cx="50" cy="50" r="40" fill="none" stroke="currentColor" stroke-width="6" class="text-white/10" />
                        <circle cx="50" cy="50" r="40" fill="none" :stroke="scoreColor(cat.value)" stroke-width="6"
                            stroke-linecap="round"
                            :stroke-dasharray="circumference"
                            :stroke-dashoffset="dashOffset(cat.value)"
                            class="transition-all duration-700" />
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="text-xl font-bold font-mono" :style="{ color: scoreColor(cat.value) }">{{ cat.value }}</span>
                    </div>
                </div>
                <span class="text-sm text-slate-400 mt-2 text-center">{{ cat.label }}</span>
            </div>
        </div>
        <p class="text-xs text-slate-500 text-center">Last audit: {{ formatDate(scores.scored_at) }}</p>

        <div class="pt-4 border-t border-white/10">
            <h4 class="text-sm font-medium text-slate-300 mb-3">Core Web Vitals</h4>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                <div v-for="vital in webVitals" :key="vital.label" class="bg-white/5 rounded-lg p-3 flex flex-col items-center">
                    <span class="text-xs text-slate-400 mb-1">{{ vital.label }}</span>
                    <span class="font-mono font-semibold text-sm px-2 py-0.5 rounded"
                        :style="{ backgroundColor: vitalColor(vital.value, vital.thresholds) + '20', color: vitalColor(vital.value, vital.thresholds) }">
                        {{ formatValue(vital.value, vital.unit) }}
                    </span>
                </div>
            </div>
        </div>

        <div v-if="monitorType === 'http'" class="pt-4 space-y-3">
            <div v-if="auditMessage" class="p-3 rounded-lg text-sm text-center transition-all"
                :class="auditMessage.type === 'success' ? 'bg-emerald-500/20 border border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border border-red-500/30 text-red-400'">
                {{ auditMessage.text }}
            </div>
            <div class="flex justify-center">
                <button @click="runAudit" :disabled="auditing"
                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:bg-emerald-600/50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2">
                    <svg v-if="auditing" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ auditing ? 'Running...' : 'Run Audit' }}
                </button>
            </div>
        </div>
    </div>
    <div v-else class="text-center py-8">
        <p class="text-slate-500">No Lighthouse data yet</p>
        <div v-if="monitorType === 'http'" class="pt-4 space-y-3">
            <div v-if="auditMessage" class="p-3 rounded-lg text-sm text-center transition-all"
                :class="auditMessage.type === 'success' ? 'bg-emerald-500/20 border border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border border-red-500/30 text-red-400'">
                {{ auditMessage.text }}
            </div>
            <div class="flex justify-center">
                <button @click="runAudit" :disabled="auditing"
                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:bg-emerald-600/50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2">
                    <svg v-if="auditing" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ auditing ? 'Running...' : 'Run Audit' }}
                </button>
            </div>
        </div>
    </div>
</template>
