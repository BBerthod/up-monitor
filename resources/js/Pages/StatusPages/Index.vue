<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import DataView from 'primevue/dataview'
import Button from 'primevue/button'
import Icon from '@/Components/Icon.vue'
import Tag from 'primevue/tag'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import GlobalScopeNote from '@/Components/GlobalScopeNote.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import CopyButton from '@/Components/CopyButton.vue'
import SkeletonStatusPageList from '@/Components/SkeletonStatusPageList.vue'
import { usePageLoading } from '@/Composables/usePageLoading'

interface StatusPage {
    id: number
    name: string
    slug: string
    is_active: boolean
    monitors_count: number
}

const props = defineProps<{
    statusPages: StatusPage[]
}>()

const { isLoading } = usePageLoading()

const showDeleteDialog = ref(false)
const pageToDelete = ref<StatusPage | null>(null)

const deleteStatusPage = (sp: StatusPage) => {
    pageToDelete.value = sp
    showDeleteDialog.value = true
}

const confirmDelete = () => {
    if (pageToDelete.value) {
        router.delete(route('status-pages.destroy', pageToDelete.value.id))
    }
}

const statusPageUrl = (slug: string) => `/status/${slug}`
</script>

<template>
    <Head title="Status Pages" />

    <SkeletonStatusPageList v-if="isLoading" />

    <div v-else class="space-y-6">
        <!-- Global scope note: status pages are not filtered by site scope -->
        <GlobalScopeNote />

        <PageHeader title="Status Pages" description="Create public pages to share uptime status with your users.">
            <template #actions>
                <Link :href="route('status-pages.create')">
                    <Button label="Create Status Page" severity="primary" class="font-semibold"><template #icon><Icon name="plus" /></template></Button>
                </Link>
            </template>
        </PageHeader>

        <DataView :value="statusPages" :layout="'list'" :paginator="statusPages.length > 10" :rows="10" class="glass overflow-hidden rounded-xl">
            <template #list="slotProps">
                <div v-for="sp in slotProps.items" :key="sp.id" class="p-4 border-b border-white/5 last:border-b-0 hover:bg-white/5 transition-colors">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-3">
                                <p class="text-white font-medium text-lg">{{ sp.name }}</p>
                                <Tag :severity="sp.is_active ? 'success' : 'secondary'" :value="sp.is_active ? 'Active' : 'Inactive'" rounded class="text-xs py-0.5 h-auto" />
                            </div>
                            <div class="flex items-center gap-3 mt-1.5">
                                <div class="flex items-center gap-1.5">
                                    <code class="text-xs text-emerald-400 bg-white/5 px-2 py-0.5 rounded font-mono">{{ statusPageUrl(sp.slug) }}</code>
                                    <CopyButton :text="statusPageUrl(sp.slug)" />
                                </div>
                                <span class="text-xs text-text-muted">{{ sp.monitors_count }} monitor{{ sp.monitors_count !== 1 ? 's' : '' }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <a :href="route('status.show', sp.slug)" target="_blank">
                                <Button label="View" text size="small" class="text-slate-300 hover:text-white"><template #icon><Icon name="external-link" :size="14" /></template></Button>
                            </a>
                            <Link :href="route('status-pages.edit', sp.id)">
                                <Button label="Edit" text size="small" class="text-slate-300 hover:text-white"><template #icon><Icon name="pencil" :size="14" /></template></Button>
                            </Link>
                            <Button label="Delete" text severity="danger" size="small" @click="deleteStatusPage(sp)"><template #icon><Icon name="trash" :size="14" /></template></Button>
                        </div>
                    </div>
                </div>
            </template>
            <template #empty>
                <EmptyState
                    title="No status pages yet"
                    description="Create a public status page to share uptime with your users."
                    icon="globe"
                    icon-color="emerald"
                >
                    <template #action>
                        <Link :href="route('status-pages.create')">
                            <Button label="Create Your First Status Page" severity="primary" class="font-semibold"><template #icon><Icon name="plus" /></template></Button>
                        </Link>
                    </template>
                </EmptyState>
            </template>
        </DataView>
    </div>

    <ConfirmDialog
        v-model:show="showDeleteDialog"
        title="Delete Status Page"
        :message="`Are you sure you want to delete '${pageToDelete?.name}'? This action cannot be undone.`"
        confirm-label="Delete"
        variant="danger"
        @confirm="confirmDelete"
    />
</template>
