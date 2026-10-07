<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import TriageItem from '@/Components/Triage/TriageItem.vue'
import { usePageLoading } from '@/Composables/usePageLoading'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import type { TriageItemData } from '@/Components/Triage/TriageItem.vue'

interface PaginatedItems {
    data: TriageItemData[]
    links: Array<{ url: string | null; label: string; active: boolean }>
    last_page: number
    total: number
}
interface InboxCounts { total: number; critical?: number; warning?: number; info?: number }
interface SeverityCounts { critical: number; warning: number; info: number }
interface GroupCounts { [key: string]: number }
interface Suggestion { label: string; url: string; count?: number }
interface GroupSection { key: string; label: string; dotColor: string; items: TriageItemData[]; totalCount: number }

const props = defineProps<{
    items?: PaginatedItems | null
    counts?: InboxCounts | null
    severityCounts?: SeverityCounts | null
    filters?: { severity?: string; domain?: string; site?: string } | null
    group?: 'severity' | 'domain' | 'site'
    groupCounts?: GroupCounts | null
    suggestions?: Suggestion[] | null
}>()

useRealtimeUpdates({
    onInsightChanged: ['items', 'counts', 'severityCounts', 'groupCounts'],
    onIncidentCreated: ['items', 'counts', 'severityCounts', 'groupCounts'],
    onIncidentResolved: ['items', 'counts', 'severityCounts', 'groupCounts'],
})

const { isLoading } = usePageLoading()
const activeGroup = computed(() => props.group ?? 'severity')
const activeSeverity = computed(() => props.filters?.severity ?? '')
// counts.total = WARNING+CRITICAL only (nav badge rule); the inbox lists
// everything open, so totals here sum all severities.
const openTotal = computed(() => (props.severityCounts?.critical ?? 0)
    + (props.severityCounts?.warning ?? 0) + (props.severityCounts?.info ?? 0))

function setFilter(severity: string) {
    router.get('/inbox', {
        ...(severity ? { severity } : {}),
        ...(props.group && props.group !== 'severity' ? { group: props.group } : {}),
    }, { preserveState: true, replace: true })
}
function setGroup(g: 'severity' | 'domain' | 'site') {
    router.get('/inbox', {
        ...(props.filters?.severity ? { severity: props.filters.severity } : {}),
        ...(g !== 'severity' ? { group: g } : {}),
    }, { preserveState: true, replace: true })
}

const sevMeta: Record<string, { label: string; dot: string }> = {
    critical: { label: 'Critical', dot: 'bg-red-500' },
    warning:  { label: 'Warning',  dot: 'bg-amber-400' },
    info:     { label: 'Info',     dot: 'bg-zinc-500' },
}
const domMeta: Record<string, { label: string; dot: string }> = {
    availability:   { label: 'Availability',   dot: 'bg-emerald-400' },
    seo_business:   { label: 'SEO & Business', dot: 'bg-blue-400' },
    infrastructure: { label: 'Infrastructure', dot: 'bg-violet-400' },
    alerting:       { label: 'Alerting',       dot: 'bg-amber-400' },
}
const groupedSections = computed((): GroupSection[] => {
    const data = props.items?.data ?? []
    if (!data.length) return []
    const g = activeGroup.value
    const buckets = new Map<string, TriageItemData[]>()
    for (const item of data) {
        const key = g === 'severity' ? item.severity : g === 'domain' ? item.domain : (item.site?.name ?? item.server?.name ?? 'Unknown')
        if (!buckets.has(key)) buckets.set(key, [])
        buckets.get(key)!.push(item)
    }
    const out: GroupSection[] = []
    if (g === 'severity') {
        for (const key of ['critical', 'warning', 'info']) {
            if (!buckets.has(key)) continue
            const m = sevMeta[key] ?? { label: key, dot: 'bg-zinc-500' }
            out.push({ key, label: m.label, dotColor: m.dot, items: buckets.get(key)!, totalCount: props.groupCounts?.[key] ?? buckets.get(key)!.length })
        }
    } else if (g === 'domain') {
        for (const [key, items] of buckets) {
            const m = domMeta[key] ?? { label: key, dot: 'bg-zinc-500' }
            out.push({ key, label: m.label, dotColor: m.dot, items, totalCount: props.groupCounts?.[key] ?? items.length })
        }
    } else {
        for (const [key, items] of buckets) {
            out.push({ key, label: key, dotColor: 'bg-zinc-400', items, totalCount: props.groupCounts?.[key] ?? items.length })
        }
    }
    return out
})
const collapsed = ref<Record<string, boolean>>({})
function toggleSection(key: string) { collapsed.value = { ...collapsed.value, [key]: !collapsed.value[key] } }
</script>

<template>
    <Head title="Inbox" />

    <div class="space-y-6">
        <PageHeader title="Inbox" :description="openTotal ? `${openTotal} item${openTotal > 1 ? 's' : ''} to address` : 'Your triage queue'">
            <template #actions>
                <span v-if="counts?.critical" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-semibold">
                    {{ counts.critical }} critical
                </span>
            </template>
        </PageHeader>

        <!-- ─── Control bar ─────────────────────────────────────────────────── -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-1.5 flex-wrap">
                <button
                    v-for="pill in [
                        { value: '',         label: 'All',      count: openTotal,                  dot: '',             activeClass: 'bg-white/10 text-white border-white/15' },
                        { value: 'critical', label: 'Critical', count: severityCounts?.critical ?? 0,      dot: 'bg-red-500',   activeClass: 'bg-red-500/15 text-red-400 border-red-500/20' },
                        { value: 'warning',  label: 'Warning',  count: severityCounts?.warning ?? 0,       dot: 'bg-amber-400', activeClass: 'bg-amber-500/15 text-amber-400 border-amber-500/20' },
                        { value: 'info',     label: 'Info',     count: severityCounts?.info ?? 0,          dot: 'bg-zinc-500',  activeClass: 'bg-zinc-500/15 text-zinc-400 border-zinc-500/20' },
                    ]"
                    :key="pill.value"
                    type="button"
                    :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                        activeSeverity === pill.value ? pill.activeClass : 'bg-white/[0.03] text-zinc-400 border-white/5 hover:bg-white/[0.06] hover:text-zinc-300']"
                    @click="setFilter(pill.value)"
                >
                    <span v-if="pill.dot" class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="pill.dot" />
                    {{ pill.label }}
                    <span class="font-mono">{{ pill.count }}</span>
                </button>
            </div>
            <div class="flex items-center rounded-lg border border-white/8 bg-white/[0.02] p-0.5 flex-shrink-0 self-start sm:self-auto">
                <span class="text-[11px] text-zinc-600 px-2 whitespace-nowrap">Group:</span>
                <button
                    v-for="g in (['severity', 'domain', 'site'] as const)"
                    :key="g"
                    type="button"
                    :class="['px-3 py-1 rounded-md text-xs font-medium transition-colors capitalize', activeGroup === g ? 'bg-white/10 text-white' : 'text-zinc-500 hover:text-zinc-300']"
                    @click="setGroup(g)"
                >{{ g }}</button>
            </div>
        </div>

        <!-- ─── Items ──────────────────────────────────────────────────────── -->
        <div v-if="!isLoading">
            <template v-if="items?.data?.length">
                <div v-for="section in groupedSections" :key="section.key" class="mb-4">
                    <button type="button" class="w-full flex items-center gap-2 py-2 px-1 text-left group/sec" @click="toggleSection(section.key)">
                        <span class="w-2 h-2 rounded-full flex-shrink-0" :class="section.dotColor" />
                        <span class="text-xs font-semibold uppercase tracking-widest text-zinc-500 group-hover/sec:text-zinc-400 transition-colors">{{ section.label }}</span>
                        <span class="text-[10px] font-mono text-zinc-700">{{ section.totalCount }}</span>
                        <span class="ml-auto text-zinc-700 group-hover/sec:text-zinc-500 transition-colors">
                            <svg class="w-3 h-3 transition-transform" :class="collapsed[section.key] ? '-rotate-90' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                        </span>
                    </button>
                    <div v-if="!collapsed[section.key]" class="space-y-1">
                        <TriageItem v-for="item in section.items" :key="item.id" :item="item" />
                    </div>
                </div>
            </template>

            <!-- Calm / empty state -->
            <div v-else class="flex flex-col items-center justify-center py-16 text-center border border-dashed border-white/5 rounded-2xl bg-white/[0.02] space-y-6">
                <div class="p-4 rounded-full bg-emerald-500/10">
                    <svg class="w-8 h-8 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <div>
                    <h4 class="text-lg font-semibold text-white">All clear</h4>
                    <p class="text-sm text-zinc-500 mt-1 max-w-sm mx-auto">No open items. Everything is running smoothly.</p>
                    <div v-if="counts != null" class="flex items-center justify-center gap-6 mt-5 flex-wrap">
                        <div class="text-center"><p class="text-2xl font-bold font-mono text-white">{{ counts.total ?? 0 }}</p><p class="text-[11px] text-zinc-600 uppercase tracking-wide mt-0.5">Open</p></div>
                        <div class="w-px h-8 bg-white/5" />
                        <div class="text-center"><p class="text-2xl font-bold font-mono text-emerald-400">{{ counts.critical ?? 0 }}</p><p class="text-[11px] text-zinc-600 uppercase tracking-wide mt-0.5">Critical</p></div>
                        <div class="w-px h-8 bg-white/5" />
                        <div class="text-center"><p class="text-2xl font-bold font-mono text-amber-400">{{ counts.warning ?? 0 }}</p><p class="text-[11px] text-zinc-600 uppercase tracking-wide mt-0.5">Warning</p></div>
                    </div>
                </div>
                <div v-if="suggestions?.length" class="w-full max-w-sm space-y-2 px-4">
                    <p class="text-[11px] text-zinc-600 uppercase tracking-wider text-left mb-1">Next steps</p>
                    <a v-for="s in suggestions" :key="s.url" :href="s.url" class="flex items-center justify-between px-4 py-2.5 rounded-lg bg-white/[0.03] border border-white/5 hover:bg-white/[0.06] hover:border-white/10 transition-colors group/sug">
                        <span class="text-sm text-zinc-300 group-hover/sug:text-white transition-colors">{{ s.label }}</span>
                        <div class="flex items-center gap-1.5">
                            <span v-if="s.count != null" class="text-xs font-mono text-zinc-600">{{ s.count }}</span>
                            <svg class="w-3.5 h-3.5 text-zinc-700 group-hover/sug:text-zinc-400 transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <!-- Skeleton while navigating -->
        <div v-else class="space-y-2">
            <div v-for="i in 5" :key="i" class="h-16 rounded-lg bg-white/[0.02] border border-white/5 animate-pulse" />
        </div>

        <!-- Pagination (Laravel standard links) -->
        <div v-if="(items?.last_page ?? 0) > 1" class="flex justify-center gap-2 pt-4">
            <template v-for="link in items?.links" :key="link.label">
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
</template>
