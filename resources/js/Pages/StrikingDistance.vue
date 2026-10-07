<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import TriageItem from '@/Components/Triage/TriageItem.vue'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import type { TriageItemData } from '@/Components/Triage/TriageItem.vue'

interface PaginatedItems {
    data: TriageItemData[]
    links: Array<{ url: string | null; label: string; active: boolean }>
    last_page: number
    total: number
}

interface Counts {
    total: number
    sites?: number
}

const props = defineProps<{
    items?: PaginatedItems | null
    counts?: Counts | null
}>()

useRealtimeUpdates({
    onInsightChanged: ['items', 'counts'],
})

const total = props.counts?.total ?? props.items?.total ?? 0
</script>

<template>
    <Head title="Striking Distance" />

    <div class="space-y-6">
        <PageHeader
            title="Striking Distance"
            description="Keywords in positions 11–20 — your quickest SEO wins"
        >
            <template #actions>
                <span
                    v-if="total > 0"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs font-semibold"
                >
                    {{ total }} opportunit{{ total === 1 ? 'y' : 'ies' }}
                </span>
            </template>
        </PageHeader>

        <!-- ─── Items ──────────────────────────────────────────────────────── -->
        <div v-if="items?.data?.length">
            <div class="space-y-1">
                <TriageItem
                    v-for="item in items.data"
                    :key="item.id"
                    :item="item"
                />
            </div>

            <!-- Pagination -->
            <div v-if="(items.last_page ?? 0) > 1" class="flex justify-center gap-2 pt-6">
                <template v-for="link in items.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="['px-3 py-1.5 text-xs font-medium rounded-md transition-colors font-mono',
                            link.active
                                ? 'bg-emerald-500/10 text-emerald-500 border border-emerald-500/20'
                                : 'text-zinc-500 hover:text-zinc-300 hover:bg-white/5']"
                        v-html="link.label"
                    />
                    <span
                        v-else
                        class="px-3 py-1.5 text-xs font-medium text-zinc-700 font-mono"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>

        <!-- ─── Empty state ───────────────────────────────────────────────── -->
        <div
            v-else
            class="flex flex-col items-center justify-center py-16 text-center border border-dashed border-white/5 rounded-2xl bg-white/[0.02] space-y-5"
        >
            <div class="p-4 rounded-full bg-amber-500/10">
                <svg class="w-8 h-8 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <circle cx="12" cy="12" r="6"/>
                    <circle cx="12" cy="12" r="2"/>
                </svg>
            </div>
            <div>
                <h4 class="text-lg font-semibold text-white">No quick wins yet</h4>
                <p class="text-sm text-zinc-500 mt-1 max-w-sm mx-auto">
                    Striking-distance opportunities appear once Search Console data is collected
                    and the detector has run.
                </p>
                <p class="text-xs text-zinc-600 mt-2">Make sure your sites have a GSC property configured.</p>
            </div>
            <Link :href="route('sites.index')" class="text-xs text-emerald-500 hover:text-emerald-400 transition-colors">
                Configure sites &rarr;
            </Link>
        </div>
    </div>
</template>
