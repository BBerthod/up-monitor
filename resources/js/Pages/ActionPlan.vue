<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import ActionPlan from '@/Components/ActionPlan.vue'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'

// ActionPlanData mirrors ActionPlan.vue's expected prop shape
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
    actions?: ActionPlanData | null
    counts?: { total?: number; critical?: number } | null
}>()

// Refresh when an insight changes (new action items may appear/disappear)
useRealtimeUpdates({
    onInsightChanged: ['actions', 'counts'],
})

// Provide a safe default so ActionPlan.vue always receives a valid ActionPlanData
const safePlan: ActionPlanData = {
    actions: props.actions?.actions ?? [],
    total_actionable: props.actions?.total_actionable ?? 0,
    generated_at: props.actions?.generated_at ?? new Date().toISOString(),
}
</script>

<template>
    <Head title="Action Plan" />

    <div class="space-y-8">
        <PageHeader title="Action Plan" description="Your top SEO & business priorities, ranked by ROI">
            <template #actions>
                <span v-if="counts?.critical" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-semibold">
                    {{ counts.critical }} critical
                </span>
            </template>
        </PageHeader>

        <ActionPlan :action-plan="safePlan" />
    </div>
</template>
