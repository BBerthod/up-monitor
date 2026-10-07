<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

interface Site {
    id: number
    alias: string
    organization: string | null
    primary_domain: string
    type: string
    has_gsc: boolean
    has_bing: boolean
    has_ga4: boolean
    is_active: boolean
    monitors_count: number
}

const props = defineProps<{
    sites: Site[]
}>()

const deleteTarget = ref<Site | null>(null)
const showDeleteDialog = ref(false)

const confirmDelete = (site: Site) => {
    deleteTarget.value = site
    showDeleteDialog.value = true
}

const handleDelete = () => {
    if (!deleteTarget.value) return
    router.delete(route('sites.destroy', deleteTarget.value.id))
    deleteTarget.value = null
}

const typeLabel: Record<string, string> = {
    laravel: 'Laravel',
    wordpress: 'WordPress',
    static: 'Static',
    compose: 'Compose',
}

const typeColors: Record<string, string> = {
    laravel: 'bg-red-500/15 text-red-400 border-red-500/20',
    wordpress: 'bg-blue-500/15 text-blue-400 border-blue-500/20',
    static: 'bg-zinc-500/15 text-zinc-400 border-zinc-500/20',
    compose: 'bg-purple-500/15 text-purple-400 border-purple-500/20',
}
</script>

<template>
    <Head title="Sites" />

    <div class="space-y-8">
        <PageHeader
            title="Sites"
            description="Manage the websites you monitor for SEO &amp; uptime."
        >
            <template #actions>
                <Link :href="route('sites.create')">
                    <button class="btn-primary flex items-center gap-2 px-4 py-2">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        Add Site
                    </button>
                </Link>
            </template>
        </PageHeader>

        <!-- Empty state -->
        <EmptyState
            v-if="sites.length === 0"
            icon="globe"
            title="No sites yet"
            description="Add your first site to start tracking its SEO."
        >
            <template #action>
                <Link :href="route('sites.create')">
                    <button class="btn-primary flex items-center gap-2 px-4 py-2">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        Add Site
                    </button>
                </Link>
            </template>
        </EmptyState>

        <!-- Sites list -->
        <div v-else class="flex flex-col gap-2">
            <div
                v-for="site in sites"
                :key="site.id"
                class="group flex items-center justify-between py-4 px-4 border border-white/5 rounded-xl bg-white/[0.02] hover:bg-white/[0.04] transition-colors gap-4"
            >
                <!-- Left: identity (links to sites.show) -->
                <Link
                    :href="route('sites.show', site.id)"
                    class="flex items-center gap-4 min-w-0 flex-1"
                >
                    <!-- Active indicator -->
                    <div class="shrink-0">
                        <span v-if="site.is_active" class="block w-2 h-2 rounded-full bg-emerald-500" />
                        <span v-else class="block w-2 h-2 rounded-full border-2 border-zinc-600 bg-transparent" />
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-white font-semibold tracking-tight">{{ site.alias }}</span>
                            <span
                                :class="['px-2 py-0.5 rounded text-[10px] font-bold uppercase border', typeColors[site.type] ?? typeColors.static]"
                            >
                                {{ typeLabel[site.type] ?? site.type }}
                            </span>
                            <span v-if="!site.is_active" class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-zinc-800 text-zinc-400">
                                Inactive
                            </span>
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="text-xs text-zinc-500 font-mono truncate">{{ site.primary_domain }}</span>
                            <span v-if="site.organization" class="text-[10px] text-zinc-600 hidden sm:inline">{{ site.organization }}</span>
                        </div>
                    </div>
                </Link>

                <!-- Middle: integrations & monitors -->
                <div class="hidden md:flex items-center gap-4 shrink-0">
                    <!-- SEO integrations -->
                    <div class="flex items-center gap-2">
                        <span
                            :class="['text-[10px] font-medium px-1.5 py-0.5 rounded border', site.has_gsc ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-zinc-600 bg-white/5 border-white/5']"
                            title="Google Search Console"
                        >GSC</span>
                        <span
                            :class="['text-[10px] font-medium px-1.5 py-0.5 rounded border', site.has_bing ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-zinc-600 bg-white/5 border-white/5']"
                            title="Bing Webmaster"
                        >Bing</span>
                        <span
                            :class="['text-[10px] font-medium px-1.5 py-0.5 rounded border', site.has_ga4 ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-zinc-600 bg-white/5 border-white/5']"
                            title="Google Analytics 4"
                        >GA4</span>
                    </div>

                    <!-- Monitors count -->
                    <div class="text-right w-20">
                        <span class="block text-sm font-medium text-zinc-300">{{ site.monitors_count }}</span>
                        <span class="text-[10px] text-zinc-600 uppercase tracking-wider">Monitors</span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-1 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                    <Link
                        :href="route('sites.edit', site.id)"
                        class="p-2 rounded-lg text-zinc-500 hover:text-white hover:bg-white/5 transition-colors"
                        :aria-label="`Edit ${site.alias}`"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                        </svg>
                    </Link>
                    <button
                        @click="confirmDelete(site)"
                        class="p-2 rounded-lg text-zinc-500 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                        :aria-label="`Delete ${site.alias}`"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                            <path d="M10 11v6" /><path d="M14 11v6" /><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete confirmation -->
    <ConfirmDialog
        v-model:show="showDeleteDialog"
        title="Delete Site"
        :message="`Are you sure you want to delete '${deleteTarget?.alias}'? This action cannot be undone.`"
        confirm-label="Delete Site"
        variant="danger"
        @confirm="handleDelete"
        @cancel="deleteTarget = null"
    />
</template>
