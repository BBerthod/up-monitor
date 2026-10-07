<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useFocusTrap } from '@/Composables/useFocusTrap'

const props = defineProps<{
    show: boolean
    monitorId?: number | null
    monitorName?: string
    target: 'checks' | 'incidents' | 'lighthouse'
}>()

const emit = defineEmits<{
    'update:show': [value: boolean]
}>()

const dialogRef = ref<HTMLElement | null>(null)
const showModel = computed({
    get: () => props.show,
    set: (v) => emit('update:show', v),
})

useFocusTrap(dialogRef, showModel)

const period = ref<string>('90d')

const targetLabels: Record<string, string> = {
    checks: 'Check history',
    incidents: 'Incidents',
    lighthouse: 'Lighthouse scores',
}

const periodOptions = [
    { value: '30d', label: 'Older than 30 days' },
    { value: '90d', label: 'Older than 90 days' },
    { value: '1y', label: 'Older than 1 year' },
    { value: 'all', label: 'All data' },
]

const periodLabel = computed(() => periodOptions.find(p => p.value === period.value)?.label ?? '')
const targetLabel = computed(() => targetLabels[props.target] ?? props.target)
const isGlobal = computed(() => !props.monitorId)

const form = useForm({})

const submit = () => {
    const routeName = isGlobal.value ? 'settings.purge' : 'monitors.purge'
    const routeArgs = isGlobal.value ? undefined : props.monitorId

    form.transform(() => ({
        target: props.target,
        period: period.value,
    })).delete(route(routeName, routeArgs), {
        preserveScroll: true,
        onSuccess: () => close(),
    })
}

const close = () => {
    showModel.value = false
}
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="purge-dialog-title">
                <div class="absolute inset-0 bg-black/60" @click="close" />

                <div
                    ref="dialogRef"
                    class="relative bg-[var(--color-surface-0)] border border-white/10 rounded-xl w-full max-w-md shadow-2xl"
                >
                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 pt-6 pb-4 border-b border-white/5">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </div>
                            <div>
                                <h2 id="purge-dialog-title" class="text-base font-semibold text-white">Purge {{ targetLabel }}</h2>
                                <p class="text-xs text-slate-500">{{ isGlobal ? 'All monitors' : monitorName }}</p>
                            </div>
                        </div>
                        <button @click="close" aria-label="Close" class="text-slate-500 hover:text-white transition-colors p-1">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="px-6 py-5 space-y-5">
                        <!-- Period -->
                        <div>
                            <p class="text-sm font-medium text-slate-300 mb-3">Period</p>
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    v-for="opt in periodOptions"
                                    :key="opt.value"
                                    :class="['flex items-center gap-2.5 px-3 py-2.5 rounded-lg border cursor-pointer transition-colors text-sm', period === opt.value ? 'bg-white/10 border-white/20 text-white' : 'border-white/5 text-slate-400 hover:border-white/10 hover:text-slate-300', opt.value === 'all' ? 'col-span-2' : '']"
                                >
                                    <input type="radio" :value="opt.value" v-model="period" class="hidden" />
                                    <span :class="['w-3.5 h-3.5 rounded-full border-2 shrink-0 flex items-center justify-center', period === opt.value ? 'border-white' : 'border-slate-600']">
                                        <span v-if="period === opt.value" class="w-1.5 h-1.5 rounded-full bg-white" />
                                    </span>
                                    {{ opt.label }}
                                    <span v-if="opt.value === 'all'" class="ml-auto text-xs text-red-400 font-medium">Irreversible</span>
                                </label>
                            </div>
                        </div>

                        <!-- Warning -->
                        <div class="flex items-start gap-3 px-3 py-3 rounded-lg bg-amber-500/5 border border-amber-500/20">
                            <svg class="w-4 h-4 text-amber-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <p class="text-xs text-amber-400/80 leading-relaxed">
                                This action is <strong class="text-amber-400">permanent and cannot be undone</strong>.
                                {{ targetLabel }} {{ periodLabel.toLowerCase() }} will be deleted
                                {{ isGlobal ? 'across all monitors' : `for ${monitorName}` }}.
                            </p>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="flex items-center justify-end gap-3 px-6 pb-6">
                        <button @click="close" class="px-4 py-2 rounded-lg text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors text-sm">
                            Cancel
                        </button>
                        <button
                            @click="submit"
                            :disabled="form.processing"
                            class="px-4 py-2 rounded-lg bg-red-500 hover:bg-red-400 text-white font-medium text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                        >
                            <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            Purge data
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
