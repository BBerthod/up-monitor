<script setup lang="ts">
import { computed, ref } from 'vue'

interface Incident {
    id: number
    started_at: string
    resolved_at: string | null
    cause: string
}

const props = defineProps<{
    incidents: Incident[]
    days?: number
}>()

const totalDays = computed(() => props.days ?? 90)
const hoveredIndex = ref<number | null>(null)

const slots = computed(() => {
    const result = []
    const now = new Date()

    for (let i = totalDays.value - 1; i >= 0; i--) {
        const dayStart = new Date(now)
        dayStart.setDate(dayStart.getDate() - i)
        dayStart.setHours(0, 0, 0, 0)
        const dayEnd = new Date(dayStart)
        dayEnd.setHours(23, 59, 59, 999)

        const dayIncidents = props.incidents.filter(inc => {
            const start = new Date(inc.started_at)
            const end = inc.resolved_at ? new Date(inc.resolved_at) : new Date()
            return start <= dayEnd && end >= dayStart
        })

        result.push({
            date: dayStart,
            dateStr: dayStart.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
            hasIncident: dayIncidents.length > 0,
            isOngoing: dayIncidents.some(i => !i.resolved_at),
            incidentCount: dayIncidents.length,
        })
    }
    return result
})

const uptimePercent = computed(() => {
    const daysWithIncident = slots.value.filter(s => s.hasIncident).length
    return ((1 - daysWithIncident / totalDays.value) * 100).toFixed(1)
})

const uptimeColor = computed(() => {
    const v = parseFloat(uptimePercent.value)
    if (v > 99) return 'text-emerald-400'
    if (v > 95) return 'text-yellow-400'
    return 'text-red-400'
})
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm text-slate-400">Last {{ totalDays }} days</span>
            <span class="text-sm font-mono font-medium" :class="uptimeColor">{{ uptimePercent }}% uptime</span>
        </div>

        <div class="relative flex gap-px overflow-x-auto pb-1" role="img" :aria-label="`Incident timeline for the last ${totalDays} days`">
            <div
                v-for="(slot, i) in slots"
                :key="i"
                class="relative flex-shrink-0 h-8 w-2.5 rounded-sm cursor-default transition-transform hover:scale-y-110 origin-bottom"
                :class="{
                    'bg-emerald-500/25 hover:bg-emerald-500/40': !slot.hasIncident,
                    'bg-red-500 hover:bg-red-400': slot.hasIncident && !slot.isOngoing,
                    'bg-red-500 animate-pulse': slot.isOngoing,
                }"
                @mouseenter="hoveredIndex = i"
                @mouseleave="hoveredIndex = null"
            >
                <Transition name="fade-up">
                    <div
                        v-if="hoveredIndex === i"
                        class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 z-20 pointer-events-none"
                    >
                        <div class="bg-slate-900 border border-white/10 rounded-lg px-3 py-2 text-xs whitespace-nowrap shadow-xl">
                            <p class="font-medium text-white mb-0.5">{{ slot.dateStr }}</p>
                            <p v-if="slot.isOngoing" class="text-red-400 font-medium">Ongoing incident</p>
                            <p v-else-if="slot.hasIncident" class="text-red-400">
                                {{ slot.incidentCount }} incident{{ slot.incidentCount > 1 ? 's' : '' }}
                            </p>
                            <p v-else class="text-emerald-400">No incidents</p>
                        </div>
                        <!-- Arrow -->
                        <div class="absolute top-full left-1/2 -translate-x-1/2 w-2 h-1 overflow-hidden">
                            <div class="w-2 h-2 bg-slate-900 border-r border-b border-white/10 rotate-45 -translate-y-1/2" />
                        </div>
                    </div>
                </Transition>
            </div>
        </div>

        <div class="flex justify-between text-xs text-slate-600 mt-1.5">
            <span>{{ totalDays }} days ago</span>
            <span>Today</span>
        </div>
    </div>
</template>

<style scoped>
.fade-up-enter-active,
.fade-up-leave-active {
    transition: opacity 0.1s ease, transform 0.1s ease;
}
.fade-up-enter-from,
.fade-up-leave-to {
    opacity: 0;
    transform: translateX(-50%) translateY(4px);
}
.fade-up-enter-to {
    transform: translateX(-50%) translateY(0);
}
</style>
