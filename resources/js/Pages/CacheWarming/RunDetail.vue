<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import BackLink from '@/Components/BackLink.vue'
import PageHeader from '@/Components/PageHeader.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'

const props = defineProps<{
    warmSite: { id: number; name: string; domain: string }
    warmRun: {
        id: number
        urls_total: number
        urls_hit: number
        urls_miss: number
        urls_error: number
        hit_ratio: number | null
        avg_response_ms: number
        status: string
        error_message: string | null
        duration_seconds: number | null
        started_at: string
        completed_at: string | null
    }
    urlResults: Array<{
        id: number
        url: string
        status_code: number
        cache_status: string
        response_time_ms: number
        error_message: string | null
    }>
}>()

const cacheStatusSeverity = (status: string) => {
    if (status === 'hit') return 'success'
    if (status === 'miss') return 'warn'
    return 'secondary'
}

const statusCodeSeverity = (code: number) => {
    if (code === 0) return 'danger'
    if (code >= 200 && code < 300) return 'success'
    if (code >= 300 && code < 400) return 'info'
    return 'danger'
}

const formatDate = (d: string) => new Date(d).toLocaleString()
const hitRatioPct = props.warmRun.hit_ratio !== null ? Math.round(props.warmRun.hit_ratio * 100) : 0
</script>

<template>
    <Head :title="`Run #${warmRun.id} — ${warmSite.name}`" />

    <div class="max-w-6xl mx-auto space-y-6">
        <BackLink :href="route('warming.show', warmSite.id)" :label="`Back to ${warmSite.name}`" />
        <PageHeader
            :title="`Run #${warmRun.id}`"
            :description="`${warmSite.domain} — ${formatDate(warmRun.started_at)}`"
        />

        <!-- Summary stats -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="glass p-4 text-center">
                <div class="text-2xl font-bold text-white">{{ warmRun.urls_total }}</div>
                <div class="text-xs text-zinc-500">Total URLs</div>
            </div>
            <div class="glass p-4 text-center">
                <div class="text-2xl font-bold text-emerald-400">{{ warmRun.urls_hit }}</div>
                <div class="text-xs text-zinc-500">Cache Hit</div>
            </div>
            <div class="glass p-4 text-center">
                <div class="text-2xl font-bold text-amber-400">{{ warmRun.urls_miss }}</div>
                <div class="text-xs text-zinc-500">Cache Miss</div>
            </div>
            <div class="glass p-4 text-center">
                <div class="text-2xl font-bold text-red-400">{{ warmRun.urls_error }}</div>
                <div class="text-xs text-zinc-500">Errors</div>
            </div>
            <div class="glass p-4 text-center">
                <div class="text-2xl font-bold text-white">{{ warmRun.avg_response_ms }}ms</div>
                <div class="text-xs text-zinc-500">Avg Response</div>
            </div>
        </div>

        <!-- Error message if any -->
        <div v-if="warmRun.error_message" class="glass p-4 border-red-500/20 border">
            <div class="text-sm text-red-400">{{ warmRun.error_message }}</div>
        </div>

        <!-- URL results table -->
        <div class="glass overflow-hidden">
            <DataTable
                :value="urlResults"
                stripedRows
                :rows="50"
                :paginator="urlResults.length > 50"
                class="p-datatable-sm"
                :pt="{ table: { class: 'w-full' } }"
            >
                <Column field="url" header="URL" :sortable="true">
                    <template #body="{ data }">
                        <a
                            :href="data.url"
                            target="_blank"
                            class="text-emerald-400 hover:underline text-sm truncate block max-w-md"
                        >
                            {{ data.url.replace(/^https?:\/\//, '') }}
                        </a>
                    </template>
                </Column>
                <Column field="status_code" header="Status" :sortable="true" style="width: 100px">
                    <template #body="{ data }">
                        <Tag
                            :value="data.status_code === 0 ? 'ERR' : String(data.status_code)"
                            :severity="statusCodeSeverity(data.status_code)"
                        />
                    </template>
                </Column>
                <Column field="cache_status" header="Cache" :sortable="true" style="width: 100px">
                    <template #body="{ data }">
                        <Tag
                            :value="data.cache_status.toUpperCase()"
                            :severity="cacheStatusSeverity(data.cache_status)"
                        />
                    </template>
                </Column>
                <Column field="response_time_ms" header="Time" :sortable="true" style="width: 100px">
                    <template #body="{ data }">
                        <span class="text-sm text-zinc-400">{{ data.response_time_ms }}ms</span>
                    </template>
                </Column>
                <Column field="error_message" header="Error" style="width: 200px">
                    <template #body="{ data }">
                        <span
                            v-if="data.error_message"
                            class="text-sm text-red-400 truncate block max-w-[200px]"
                        >{{ data.error_message }}</span>
                        <span v-else class="text-zinc-600">—</span>
                    </template>
                </Column>
            </DataTable>
        </div>
    </div>
</template>
