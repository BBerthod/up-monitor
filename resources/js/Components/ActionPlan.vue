<script setup lang="ts">
import { computed } from 'vue'
import GlassCard from '@/Components/GlassCard.vue'
import EmptyState from '@/Components/EmptyState.vue'

interface ActionItem {
    insight_id: number
    site: string
    type: string
    severity: string
    title: string
    action: string
    impact: number
    effort: number
    priority_score: number
}

interface ActionPlanData {
    actions: ActionItem[]
    total_actionable: number
    generated_at: string
}

const props = defineProps<{
    actionPlan: ActionPlanData
}>()

// Impact is metadata, so it stays neutral regardless of its value.
const impactColorClass = (_impact: number): string =>
    'bg-transparent text-zinc-400 border-zinc-800'

// Severity pill color for insight severities (info/opportunity/warning/critical)
const severityColorClass = (severity: string): string => {
    const map: Record<string, string> = {
        critical:    'bg-red-500/15 text-red-400 border-red-500/20',
        warning:     'bg-amber-500/15 text-amber-400 border-amber-500/20',
        opportunity: 'bg-transparent text-zinc-400 border-zinc-800',
        info:        'bg-transparent text-zinc-400 border-zinc-800',
    }
    return map[severity] ?? 'bg-transparent text-zinc-400 border-zinc-800'
}

// Human-readable type label
const typeLabel = (type: string): string => {
    const map: Record<string, string> = {
        striking_distance: 'Striking distance',
        content_decay:     'Content decay',
        revenue_at_risk:   'Revenue at risk',
        traffic_change:    'Traffic change',
        position_change:   'Position change',
        ctr_change:        'CTR change',
        perf_regression:   'Perf regression',
    }
    return map[type] ?? type.replace(/_/g, ' ')
}

const generatedAtLabel = computed(() => {
    if (!props.actionPlan.generated_at) return null
    const ms = Date.now() - new Date(props.actionPlan.generated_at).getTime()
    const m = Math.floor(ms / 60000)
    if (m < 1) return 'Just now'
    if (m < 60) return `${m}m ago`
    if (m < 1440) return `${Math.floor(m / 60)}h ago`
    return `${Math.floor(m / 1440)}d ago`
})
</script>

<template>
    <GlassCard title="Action Plan">
        <template #actions>
            <div class="flex items-center gap-3">
                <span v-if="actionPlan.total_actionable > 0" class="text-xs text-zinc-500">
                    {{ actionPlan.total_actionable }} actionable
                </span>
                <span v-if="generatedAtLabel" class="text-xs text-zinc-600">
                    Updated {{ generatedAtLabel }}
                </span>
            </div>
        </template>

        <!-- Sub-title -->
        <p class="text-xs text-zinc-500 -mt-2 mb-5">Your top SEO priorities this week</p>

        <EmptyState
            v-if="actionPlan.actions.length === 0"
            title="No actions needed"
            description="Your portfolio is in good shape — no SEO actions pending."
            icon="check"
            iconColor="emerald"
        />

        <ol v-else class="space-y-0 divide-y divide-white/5">
            <li
                v-for="(item, index) in actionPlan.actions"
                :key="item.insight_id"
                class="flex gap-4 py-4 first:pt-0 last:pb-0"
            >
                <!-- Rank number -->
                <div class="flex-shrink-0 flex items-start justify-center pt-0.5">
                    <span class="w-6 h-6 flex items-center justify-center rounded bg-white/[0.04] border border-white/8 text-[11px] font-semibold text-zinc-500 leading-none">
                        {{ index + 1 }}
                    </span>
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0 space-y-2">
                    <!-- Action text (the concrete recommendation) -->
                    <p class="text-sm font-medium text-white leading-snug">{{ item.action }}</p>

                    <!-- Site + type label -->
                    <div class="flex items-center flex-wrap gap-1.5">
                        <span class="text-xs text-zinc-500 font-mono truncate max-w-[180px]">{{ item.site }}</span>
                        <span class="text-zinc-700">&middot;</span>
                        <span class="text-xs text-zinc-500">{{ typeLabel(item.type) }}</span>
                    </div>

                    <!-- Badges row -->
                    <div class="flex items-center flex-wrap gap-1.5">
                        <!-- Severity badge -->
                        <span
                            :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium border', severityColorClass(item.severity)]"
                        >
                            {{ item.severity.charAt(0).toUpperCase() + item.severity.slice(1) }}
                        </span>

                        <!-- Impact badge -->
                        <span
                            :class="['inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium border', impactColorClass(item.impact)]"
                        >
                            Impact&nbsp;{{ item.impact }}
                        </span>

                        <!-- Effort badge -->
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium border bg-transparent text-zinc-400 border-zinc-800">
                            Effort&nbsp;{{ item.effort }}/5
                        </span>

                        <!-- ROI score (discrete) -->
                        <span class="text-[11px] font-mono text-zinc-600 ml-0.5">
                            ROI&nbsp;{{ item.priority_score }}
                        </span>
                    </div>
                </div>
            </li>
        </ol>
    </GlassCard>
</template>
