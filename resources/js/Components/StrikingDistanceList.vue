<script setup lang="ts">
import GlassCard from '@/Components/GlassCard.vue'
import EmptyState from '@/Components/EmptyState.vue'

interface StrikingOpportunity {
    id: number
    title: string
    impact_score: number
    query: string | null
    page: string | null
    position: number | null
    impressions: number | null
    ctr: number | null
    estimated_gain: number | null
}

const props = defineProps<{ opportunities: StrikingOpportunity[] }>()

const fmt = new Intl.NumberFormat('en-US')

const fmtImpressions = (n: number | null): string => {
    if (n === null) return '—'
    return fmt.format(n) + ' impr/mo'
}

const fmtGain = (n: number | null): string => {
    if (n === null) return '—'
    return '+' + Math.round(n) + ' clicks/mo'
}

const fmtCtr = (n: number | null): string => {
    if (n === null) return '—'
    // ctr arrives already expressed as a percentage from the backend.
    return n.toFixed(1) + '%'
}

const fmtPosition = (n: number | null): string => {
    if (n === null) return '—'
    return '#' + Math.round(n)
}
</script>

<template>
    <GlassCard title="SEO Quick Wins">
        <template #actions>
            <span class="text-xs text-zinc-500">Queries ranking 11–20 — small improvements, big traffic</span>
        </template>

        <EmptyState
            v-if="!opportunities.length"
            title="No quick wins detected yet"
            description="Striking-distance opportunities appear here once Search Console data is analyzed."
            icon="chart"
            iconColor="amber"
        />

        <ul v-else class="divide-y divide-white/5">
            <li
                v-for="opp in opportunities"
                :key="opp.id"
                class="py-4 first:pt-0 last:pb-0 flex flex-col sm:flex-row sm:items-start gap-3"
            >
                <!-- Left: query + position badge -->
                <div class="flex items-center gap-2 min-w-0 flex-1">
                    <span
                        class="shrink-0 inline-flex items-center justify-center px-2 py-0.5 rounded text-xs font-semibold bg-transparent text-zinc-400 border border-zinc-800 tabular-nums"
                        :title="`Current position: ${fmtPosition(opp.position)}`"
                    >
                        {{ fmtPosition(opp.position) }}
                    </span>
                    <span class="text-white font-medium truncate" :title="opp.query ?? undefined">
                        {{ opp.query ?? opp.title }}
                    </span>
                </div>

                <!-- Right: stats row -->
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 shrink-0 text-sm">
                    <!-- Estimated gain — primary CTA metric -->
                    <span
                        class="font-semibold tabular-nums"
                        :class="opp.estimated_gain ? 'text-emerald-400' : 'text-zinc-500'"
                    >
                        {{ fmtGain(opp.estimated_gain) }}
                    </span>

                    <!-- Impressions -->
                    <span class="text-zinc-400 tabular-nums">{{ fmtImpressions(opp.impressions) }}</span>

                    <!-- CTR -->
                    <span class="text-zinc-500 text-xs tabular-nums">CTR {{ fmtCtr(opp.ctr) }}</span>

                    <!-- Page link -->
                    <a
                        v-if="opp.page"
                        :href="opp.page"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors max-w-[180px] truncate"
                        :title="opp.page"
                    >
                        {{ opp.page }}
                    </a>
                </div>
            </li>
        </ul>
    </GlassCard>
</template>
