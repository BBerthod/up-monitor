<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import GlobalScopeNote from '@/Components/GlobalScopeNote.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import SkeletonChannelList from '@/Components/SkeletonChannelList.vue'
import { usePageLoading } from '@/Composables/usePageLoading'

interface Channel {
    id: number
    name: string
    type: string
    is_active: boolean
    summary: string
}

const props = defineProps<{
    channels: Channel[]
}>()

const { isLoading } = usePageLoading()

const showDeleteDialog = ref(false)
const channelToDelete = ref<Channel | null>(null)

const deleteChannel = (channel: Channel) => {
    channelToDelete.value = channel
    showDeleteDialog.value = true
}

const confirmDelete = () => {
    if (channelToDelete.value) {
        router.delete(route('channels.destroy', channelToDelete.value.id))
    }
}

const testChannel = (channel: Channel) => {
    router.post(route('channels.test', channel.id))
}
</script>

<template>
    <Head title="Notification Channels" />

    <SkeletonChannelList v-if="isLoading" />

    <div v-else class="space-y-6">
        <!-- Global scope note: notification channels are not filtered by site scope -->
        <GlobalScopeNote />

        <PageHeader title="Notification Channels" description="Manage how you receive alerts when monitors go down.">
            <template #actions>
                <Link :href="route('channels.create')" class="btn-primary py-3 px-4">
                    Add Channel
                </Link>
            </template>
        </PageHeader>

        <div v-if="channels.length > 0" class="space-y-4">
            <div v-for="channel in channels" :key="channel.id" class="glass p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div :class="['status-dot', channel.is_active ? 'online' : '']" :style="!channel.is_active ? 'background-color: var(--color-muted)' : ''" />
                        <div>
                            <p class="text-white font-medium">{{ channel.name }}</p>
                            <span class="px-2 py-0.5 rounded text-xs font-medium uppercase bg-transparent text-zinc-400 border border-zinc-800">{{ channel.type }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="testChannel(channel)" aria-label="Send a test notification" class="px-3 py-1.5 rounded-lg text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors">Test</button>
                        <Link :href="route('channels.edit', channel.id)" aria-label="Edit channel settings" class="px-3 py-1.5 rounded-lg text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors">Edit</Link>
                        <button @click="deleteChannel(channel)" aria-label="Delete this channel" class="px-3 py-1.5 rounded-lg text-sm text-red-400 hover:text-red-300 bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 transition-colors">Delete</button>
                    </div>
                </div>
            </div>
        </div>

        <EmptyState
            v-else
            title="No notification channels yet"
            description="Set up channels to receive alerts when monitors go down."
            icon="bell"
            icon-color="emerald"
        >
            <template #action>
                <Link :href="route('channels.create')" class="btn-primary py-3 px-4 inline-block">Create Your First Channel</Link>
            </template>
        </EmptyState>
    </div>

    <ConfirmDialog
        v-model:show="showDeleteDialog"
        title="Delete Channel"
        :message="`Are you sure you want to delete '${channelToDelete?.name}'? This action cannot be undone.`"
        confirm-label="Delete"
        variant="danger"
        @confirm="confirmDelete"
    />
</template>
