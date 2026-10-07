<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import GlassCard from '@/Components/GlassCard.vue'
import EmptyState from '@/Components/EmptyState.vue'

interface LogEntry {
    id: number
    monitor_id: number
    monitor_name: string
    channel_name: string | null
    channel_type: string
    event: string
    status: string
    error_message: string | null
    sent_at: string
}

interface PaginatedLogs {
    data: LogEntry[]
    links: { url: string | null; label: string; active: boolean }[]
    current_page: number
    last_page: number
}

const props = defineProps<{
    logs: PaginatedLogs
    monitors: { id: number; name: string }[]
    channelTypes: { value: string; label: string }[]
    filters: Record<string, string>
}>()

const channelType = ref(props.filters.channel_type ?? '')
const monitorId = ref(props.filters.monitor_id ?? '')
const event = ref(props.filters.event ?? '')
const status = ref(props.filters.status ?? '')

const applyFilters = () => {
    router.get(route('notification-logs.index'), {
        channel_type: channelType.value || undefined,
        monitor_id: monitorId.value || undefined,
        event: event.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, replace: true })
}

watch([channelType, monitorId, event, status], applyFilters)

const formatDate = (iso: string) => {
    const d = new Date(iso)
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
    <Head title="Notification History" />

    <PageHeader title="Notification History" description="Log of all sent notifications" />

    <GlassCard :padding="0">
        <!-- Filters -->
        <div class="flex flex-wrap gap-3 p-4 border-b border-white/5">
            <select v-model="channelType" class="bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-sm text-white">
                <option value="">All channels</option>
                <option v-for="ct in channelTypes" :key="ct.value" :value="ct.value">{{ ct.label }}</option>
            </select>
            <select v-model="monitorId" class="bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-sm text-white">
                <option value="">All monitors</option>
                <option v-for="m in monitors" :key="m.id" :value="m.id">{{ m.name }}</option>
            </select>
            <select v-model="event" class="bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-sm text-white">
                <option value="">All events</option>
                <option value="down">Down</option>
                <option value="up">Up</option>
            </select>
            <select v-model="status" class="bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-sm text-white">
                <option value="">All statuses</option>
                <option value="sent">Sent</option>
                <option value="failed">Failed</option>
            </select>
        </div>

        <!-- List -->
        <div v-if="logs.data.length" class="divide-y divide-white/5">
            <div
                v-for="log in logs.data"
                :key="log.id"
                class="flex items-center gap-4 px-4 py-3 hover:bg-white/[0.02] transition-colors"
            >
                <!-- Status dot -->
                <span
                    :class="[
                        'w-2 h-2 rounded-full shrink-0',
                        log.status === 'sent' ? 'bg-emerald-500' : 'bg-red-500'
                    ]"
                />

                <!-- Monitor -->
                <Link
                    :href="route('monitors.show', log.monitor_id)"
                    class="text-sm font-medium text-white hover:text-emerald-400 transition-colors min-w-0 truncate w-40"
                >
                    {{ log.monitor_name }}
                </Link>

                <!-- Channel -->
                <span class="text-xs px-2 py-0.5 rounded bg-transparent border border-zinc-800 text-zinc-400 shrink-0">
                    {{ log.channel_type }}
                </span>
                <span class="text-xs text-zinc-500 truncate w-24">{{ log.channel_name ?? 'Deleted' }}</span>

                <!-- Event -->
                <span
                    :class="[
                        'text-xs font-medium px-2 py-0.5 rounded border shrink-0',
                        log.event === 'down' ? 'bg-red-500/10 text-red-400 border-red-500/20' : 'bg-transparent text-zinc-400 border-zinc-800'
                    ]"
                >
                    {{ log.event }}
                </span>

                <!-- Error (if failed) -->
                <span
                    v-if="log.error_message"
                    class="text-xs text-red-400/70 truncate flex-1"
                    :title="log.error_message"
                >
                    {{ log.error_message }}
                </span>
                <span v-else class="flex-1" />

                <!-- Timestamp -->
                <span class="text-xs text-zinc-500 tabular-nums shrink-0">
                    {{ formatDate(log.sent_at) }}
                </span>
            </div>
        </div>

        <EmptyState
            v-else
            icon="bell"
            title="No notification logs"
            description="Notifications will appear here once monitors trigger alerts."
        />

        <!-- Pagination -->
        <div v-if="logs.last_page > 1" class="flex justify-center gap-1 p-4 border-t border-white/5">
            <template v-for="link in logs.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    :class="[
                        'px-3 py-1 rounded text-sm transition-colors',
                        link.active ? 'bg-emerald-500/20 text-emerald-400' : 'text-zinc-500 hover:text-white hover:bg-white/5'
                    ]"
                    v-html="link.label"
                    preserve-state
                />
                <span v-else class="px-3 py-1 text-sm text-zinc-600" v-html="link.label" />
            </template>
        </div>
    </GlassCard>
</template>
