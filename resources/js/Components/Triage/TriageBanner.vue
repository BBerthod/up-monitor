<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import TriageItem from './TriageItem.vue'
import type { TriageItemData } from './TriageItem.vue'

interface TriageCounts {
    total: number
    critical: number
    domains?: Record<string, number>
}

interface TriageBreakdown {
    warnings?: number
    // Backend key: info + opportunity insights share one bucket.
    info_opportunity?: number
}

const props = defineProps<{
    items: TriageItemData[]
    counts?: TriageCounts | null
    breakdown?: TriageBreakdown | null
    availability?: { monitors_total?: number; monitors_up?: number; incidents_open?: number } | null
}>()

const emit = defineEmits<{
    acknowledged: [id: number]
}>()

const visibleItems = computed(() => (props.items ?? []).slice(0, 3))
const hiddenWarnings = computed(() => props.breakdown?.warnings ?? 0)
const hiddenInfo = computed(() => props.breakdown?.info_opportunity ?? 0)
const hasHidden = computed(() => hiddenWarnings.value > 0 || hiddenInfo.value > 0)
const isCalm = computed(() => !props.items?.length)

const totalUp = computed(() => props.availability?.monitors_up ?? 0)
const totalMonitors = computed(() => props.availability?.monitors_total ?? 0)
</script>

<template>
    <!-- Calm state: contract to single line, no decorative colour -->
    <div v-if="isCalm" class="flex items-center gap-2.5 py-2.5 px-4 rounded-md bg-[var(--color-surface-0)] border border-[var(--color-border)]">
        <span class="status-dot online" />
        <span class="text-sm text-[var(--color-text-secondary)] font-medium">Nothing to address</span>
        <span class="text-[var(--color-text-faint)] text-sm">—</span>
        <span class="text-sm text-[var(--color-text-muted)]">{{ totalUp }}/{{ totalMonitors }} monitors up · 0 open incidents</span>
    </div>

    <!-- Active state: banner with items -->
    <div v-else class="rounded-md bg-[var(--color-surface-0)] border border-[var(--color-border)] overflow-hidden">
        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-[var(--color-border)]">
            <div class="flex items-center gap-2">
                <span class="section-label !text-[var(--color-text-secondary)]">To address now</span>
                <span v-if="counts?.total" class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded text-[10px] font-bold bg-[var(--color-danger)]/15 text-[var(--color-danger)] border border-[var(--color-danger)]/25">
                    {{ counts.total }}
                </span>
            </div>
            <Link href="/inbox" class="text-xs text-[var(--color-primary-500)] hover:text-[var(--color-primary-400)] transition-colors font-medium">
                Open inbox &rarr;
            </Link>
        </div>

        <!-- Items -->
        <div class="divide-y divide-[var(--color-border)]">
            <TriageItem
                v-for="item in visibleItems"
                :key="item.id"
                :item="item"
                @acknowledged="emit('acknowledged', $event)"
            />
        </div>

        <!-- Overflow line -->
        <div v-if="hasHidden" class="px-4 py-2 border-t border-[var(--color-border)] flex items-center gap-1.5 text-xs text-[var(--color-text-muted)]">
            <span>&#8230;</span>
            <span v-if="hiddenWarnings > 0">{{ hiddenWarnings }} more warning{{ hiddenWarnings > 1 ? 's' : '' }}</span>
            <span v-if="hiddenWarnings > 0 && hiddenInfo > 0" class="text-[var(--color-text-faint)]">·</span>
            <span v-if="hiddenInfo > 0">{{ hiddenInfo }} info</span>
        </div>
    </div>
</template>
