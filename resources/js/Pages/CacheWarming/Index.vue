<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SiteScopeBanner from '@/Components/SiteScopeBanner.vue'
import Button from 'primevue/button'
import Icon from '@/Components/Icon.vue'
import Tag from 'primevue/tag'
import { computed } from 'vue'
import { useRealtimeUpdates } from '@/Composables/useRealtimeUpdates'
import type { SiteScope } from '@/Types/page'

interface LastRun {
    urls_total: number
    urls_hit: number
    urls_miss: number
    urls_error: number
    hit_ratio: number
    avg_response_ms: number
    status: string
}

interface WarmSite {
    id: number
    name: string
    domain: string
    mode: string
    mode_label: string
    frequency_minutes: number
    is_active: boolean
    last_warmed_at: string | null
    last_run: LastRun | null
}

const props = defineProps<{
    warmSites: WarmSite[]
}>()

useRealtimeUpdates({
    onWarmRunProgress: ['warmSites'],
})

const frequencyLabel = (mins: number): string => {
    if (mins < 60) return `Every ${mins} min`
    if (mins === 60) return 'Every hour'
    if (mins < 1440) return `Every ${mins / 60} hours`
    return 'Every 24 hours'
}

const hitRatioBadge = (ratio: number) => {
    const pct = Math.round(ratio * 100)
    if (pct >= 80) return { severity: 'success', label: `${pct}% hit` }
    if (pct >= 50) return { severity: 'warn', label: `${pct}% hit` }
    return { severity: 'danger', label: `${pct}% hit` }
}

const warmNow = (id: number) => {
    router.post(route('warming.warm-now', id))
}

const page = usePage()
const siteScope = computed((): SiteScope | null => (page.props as any).siteScope ?? null)
const isSiteScoped = computed(() => siteScope.value?.mode === 'site')
</script>

<template>
    <Head title="Cache Warming" />

    <div class="space-y-8">
        <!-- Site scope banner — shown when filter is active -->
        <SiteScopeBanner />

        <!-- Header -->
        <PageHeader
            title="Cache Warming"
            description="Automatically warm your site caches to ensure fast load times for real visitors."
        >
            <template #actions>
                <Link :href="route('warming.create')">
                    <Button label="New Warm Site" class="font-medium"><template #icon><Icon name="plus" /></template></Button>
                </Link>
            </template>
        </PageHeader>

        <!-- Empty state: site-scoped vs global -->
        <div v-if="warmSites.length === 0" class="flex flex-col items-center justify-center py-20 text-center border border-dashed border-white/5 rounded-2xl bg-white/[0.02]">
            <div class="p-4 mb-4 rounded-full bg-emerald-500/10">
                <svg class="w-8 h-8 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <template v-if="isSiteScoped">
                <h3 class="text-lg font-medium text-white">No warm sites for {{ siteScope?.site?.name }}</h3>
                <p class="text-zinc-500 mt-1 max-w-sm mb-6">No cache warming jobs are associated with this site.</p>
                <Link :href="route('warming.index') + '?site=all'">
                    <Button label="View all warm sites" severity="secondary" />
                </Link>
            </template>
            <template v-else>
                <h3 class="text-lg font-medium text-white">No warm sites yet</h3>
                <p class="text-zinc-500 mt-1 max-w-sm mb-6">Get started by adding your first site to keep its cache warm.</p>
                <Link :href="route('warming.create')">
                    <Button label="Add Warm Site"><template #icon><Icon name="plus" /></template></Button>
                </Link>
            </template>
        </div>

        <!-- Sites grid -->
        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="site in warmSites"
                :key="site.id"
                class="glass p-5 flex flex-col gap-4"
            >
                <!-- Top row: name + mode badge -->
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <Link
                            :href="route('warming.show', site.id)"
                            class="text-white font-semibold hover:text-emerald-400 transition-colors truncate block"
                        >
                            {{ site.name }}
                        </Link>
                        <p class="text-xs text-zinc-500 font-mono truncate mt-0.5">{{ site.domain }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <Tag
                            :value="site.mode_label"
                            :severity="site.mode === 'sitemap' ? 'info' : 'secondary'"
                            class="text-[11px]"
                        />
                        <span
                            v-if="!site.is_active"
                            class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-zinc-800 text-zinc-400"
                        >
                            Paused
                        </span>
                    </div>
                </div>

                <!-- Frequency -->
                <div class="flex items-center gap-2 text-sm text-zinc-400">
                    <svg class="w-3.5 h-3.5 shrink-0 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    {{ frequencyLabel(site.frequency_minutes) }}
                </div>

                <!-- Last run stats -->
                <div class="flex items-center justify-between">
                    <div v-if="site.last_run" class="flex items-center gap-3">
                        <Tag
                            :value="hitRatioBadge(site.last_run.hit_ratio).label"
                            :severity="hitRatioBadge(site.last_run.hit_ratio).severity as any"
                        />
                        <span class="text-xs text-zinc-500">
                            {{ site.last_run.avg_response_ms }}ms avg
                        </span>
                    </div>
                    <div v-else class="text-xs text-zinc-600 italic">Never run</div>

                    <!-- Warm Now button -->
                    <button
                        @click.prevent="warmNow(site.id)"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20 transition-colors"
                        :aria-label="`Warm ${site.name} now`"
                    >
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Warm Now
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
