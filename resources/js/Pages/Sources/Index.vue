<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import GlobalScopeNote from '@/Components/GlobalScopeNote.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import GlassCard from '@/Components/GlassCard.vue'

interface NotificationChannel {
    id: number
    name: string
    type: string
}

interface IngestSource {
    id: number
    name: string
    slug: string
    token: string
    is_active: boolean
    events_count: number
    notification_channels: NotificationChannel[]
    created_at: string
}

const props = defineProps<{
    sources: IngestSource[]
    notificationChannels: NotificationChannel[]
}>()

// Create form
const showCreateForm = ref(false)
const createForm = useForm({
    name: '',
    is_active: true,
    notification_channel_ids: [] as number[],
})

const submitCreate = () => {
    createForm.post(route('sources.store'), {
        onSuccess: () => {
            createForm.reset()
            showCreateForm.value = false
        },
    })
}

// Edit form
const editingSource = ref<IngestSource | null>(null)
const editForm = useForm({
    name: '',
    is_active: true,
    notification_channel_ids: [] as number[],
})

const openEdit = (source: IngestSource) => {
    editingSource.value = source
    editForm.name = source.name
    editForm.is_active = source.is_active
    editForm.notification_channel_ids = source.notification_channels.map(c => c.id)
}

const submitEdit = () => {
    if (!editingSource.value) return
    editForm.put(route('sources.update', editingSource.value.id), {
        onSuccess: () => {
            editingSource.value = null
        },
    })
}

// Delete
const showDeleteDialog = ref(false)
const sourceToDelete = ref<IngestSource | null>(null)

const confirmDelete = (source: IngestSource) => {
    sourceToDelete.value = source
    showDeleteDialog.value = true
}

const doDelete = () => {
    if (!sourceToDelete.value) return
    router.delete(route('sources.destroy', sourceToDelete.value.id))
}

// Token management
const visibleTokens = ref<Set<number>>(new Set())
const copiedToken = ref<number | null>(null)

const toggleToken = (id: number) => {
    if (visibleTokens.value.has(id)) {
        visibleTokens.value.delete(id)
    } else {
        visibleTokens.value.add(id)
    }
}

const copyToken = async (source: IngestSource) => {
    await navigator.clipboard.writeText(source.token)
    copiedToken.value = source.id
    setTimeout(() => { copiedToken.value = null }, 2000)
}

// Rotation invalidates the old token immediately: every client still pushing
// with it starts getting 401s. That deserves the same confirm treatment as
// Delete, plus visible feedback that it happened.
const showRotateDialog = ref(false)
const sourceToRotate = ref<IngestSource | null>(null)
const rotatedSourceId = ref<number | null>(null)
const rotating = ref(false)

const confirmRotate = (source: IngestSource) => {
    sourceToRotate.value = source
    showRotateDialog.value = true
}

const doRotate = () => {
    if (!sourceToRotate.value) return
    const id = sourceToRotate.value.id
    rotating.value = true
    router.post(route('sources.rotate-token', id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            rotatedSourceId.value = id
            setTimeout(() => { rotatedSourceId.value = null }, 3000)
        },
        onFinish: () => { rotating.value = false },
    })
}

const maskToken = (token: string) => token.slice(0, 8) + '•'.repeat(24) + token.slice(-8)

const typeColors: Record<string, string> = {
    email: 'bg-blue-500/20 text-blue-400',
    webhook: 'bg-purple-500/20 text-purple-400',
    slack: 'bg-orange-500/20 text-orange-400',
    discord: 'bg-indigo-500/20 text-indigo-400',
    telegram: 'bg-sky-500/20 text-sky-400',
    push: 'bg-green-500/20 text-green-400',
}

const toggleChannel = (form: typeof createForm | typeof editForm, id: number) => {
    const idx = form.notification_channel_ids.indexOf(id)
    if (idx === -1) {
        form.notification_channel_ids.push(id)
    } else {
        form.notification_channel_ids.splice(idx, 1)
    }
}
</script>

<template>
    <Head title="Sources" />

    <div class="space-y-6">
        <!-- Global scope note: sources are not filtered by site scope -->
        <GlobalScopeNote />

        <PageHeader title="Ingest Sources" description="Manage sources that push events to Up.">
            <template #actions>
                <button @click="showCreateForm = true" class="btn-primary py-3 px-4">
                    Add Source
                </button>
            </template>
        </PageHeader>

        <!-- Create Form -->
        <GlassCard v-if="showCreateForm" class="border border-emerald-500/20">
            <h3 class="text-base font-semibold text-white mb-4">New Ingest Source</h3>
            <form @submit.prevent="submitCreate" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-zinc-400 mb-1">Name</label>
                    <input
                        v-model="createForm.name"
                        type="text"
                        placeholder="e.g. example-shop FR"
                        class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none"
                        required
                    />
                    <p v-if="createForm.errors.name" class="mt-1 text-xs text-red-400">{{ createForm.errors.name }}</p>
                </div>

                <div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="createForm.is_active" type="checkbox" class="rounded border-white/20" />
                        <span class="text-sm text-zinc-300">Active</span>
                    </label>
                </div>

                <div v-if="notificationChannels.length > 0">
                    <label class="block text-xs font-medium text-zinc-400 mb-2">Notification Channels</label>
                    <div class="space-y-1">
                        <label
                            v-for="ch in notificationChannels"
                            :key="ch.id"
                            class="flex items-center gap-2 cursor-pointer py-1"
                        >
                            <input
                                type="checkbox"
                                :checked="createForm.notification_channel_ids.includes(ch.id)"
                                @change="toggleChannel(createForm, ch.id)"
                                class="rounded border-white/20"
                            />
                            <span class="text-sm text-zinc-300">{{ ch.name }}</span>
                            <span :class="['px-1.5 py-0.5 rounded text-xs font-medium', typeColors[ch.type] || 'bg-zinc-500/20 text-zinc-400']">{{ ch.type }}</span>
                        </label>
                    </div>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="submit" :disabled="createForm.processing" class="btn-primary px-4 py-2 text-sm">
                        Create
                    </button>
                    <button type="button" @click="showCreateForm = false" class="px-4 py-2 text-sm text-zinc-400 hover:text-white transition-colors">
                        Cancel
                    </button>
                </div>
            </form>
        </GlassCard>

        <!-- Sources List -->
        <div v-if="sources.length > 0" class="space-y-3">
            <GlassCard v-for="source in sources" :key="source.id">
                <!-- Edit inline -->
                <div v-if="editingSource?.id === source.id">
                    <form @submit.prevent="submitEdit" class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-zinc-400 mb-1">Name</label>
                            <input
                                v-model="editForm.name"
                                type="text"
                                class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none"
                                required
                            />
                        </div>
                        <div>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="editForm.is_active" type="checkbox" class="rounded border-white/20" />
                                <span class="text-sm text-zinc-300">Active</span>
                            </label>
                        </div>
                        <div v-if="notificationChannels.length > 0">
                            <label class="block text-xs font-medium text-zinc-400 mb-2">Notification Channels</label>
                            <div class="space-y-1">
                                <label v-for="ch in notificationChannels" :key="ch.id" class="flex items-center gap-2 cursor-pointer py-1">
                                    <input
                                        type="checkbox"
                                        :checked="editForm.notification_channel_ids.includes(ch.id)"
                                        @change="toggleChannel(editForm, ch.id)"
                                        class="rounded border-white/20"
                                    />
                                    <span class="text-sm text-zinc-300">{{ ch.name }}</span>
                                    <span :class="['px-1.5 py-0.5 rounded text-xs font-medium', typeColors[ch.type] || 'bg-zinc-500/20 text-zinc-400']">{{ ch.type }}</span>
                                </label>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="editForm.processing" class="btn-primary px-4 py-2 text-sm">Save</button>
                            <button type="button" @click="editingSource = null" class="px-4 py-2 text-sm text-zinc-400 hover:text-white transition-colors">Cancel</button>
                        </div>
                    </form>
                </div>

                <!-- Display mode -->
                <div v-else>
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <div :class="['w-2 h-2 rounded-full shrink-0 mt-1', source.is_active ? 'bg-emerald-400' : 'bg-zinc-600']" />
                            <div class="min-w-0">
                                <p class="text-white font-medium">{{ source.name }}</p>
                                <p class="text-xs text-zinc-500 font-mono mt-0.5">{{ source.slug }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-xs text-zinc-500">{{ source.events_count }} events</span>
                            <button @click="openEdit(source)" class="px-3 py-1.5 rounded-lg text-sm text-zinc-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors">Edit</button>
                            <button @click="confirmDelete(source)" class="px-3 py-1.5 rounded-lg text-sm text-red-400 hover:text-red-300 bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 transition-colors">Delete</button>
                        </div>
                    </div>

                    <!-- Token row -->
                    <div class="mt-3 pt-3 border-t border-white/5">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-zinc-500">Token:</span>
                            <code class="flex-1 text-xs font-mono text-zinc-300 bg-white/5 px-2 py-1 rounded truncate">
                                {{ visibleTokens.has(source.id) ? source.token : maskToken(source.token) }}
                            </code>
                            <button @click="toggleToken(source.id)" class="px-2 py-1 text-xs text-zinc-400 hover:text-white transition-colors">
                                {{ visibleTokens.has(source.id) ? 'Hide' : 'Show' }}
                            </button>
                            <button @click="copyToken(source)" class="px-2 py-1 text-xs transition-colors" :class="copiedToken === source.id ? 'text-emerald-400' : 'text-zinc-400 hover:text-white'">
                                {{ copiedToken === source.id ? 'Copied!' : 'Copy' }}
                            </button>
                            <button
                                @click="confirmRotate(source)"
                                :disabled="rotating"
                                :class="[
                                    'px-2 py-1 text-xs transition-colors disabled:opacity-50',
                                    rotatedSourceId === source.id ? 'text-emerald-400' : 'text-yellow-500/70 hover:text-yellow-400',
                                ]"
                            >
                                {{ rotatedSourceId === source.id ? 'Rotated!' : 'Rotate' }}
                            </button>
                        </div>
                    </div>

                    <!-- Channels -->
                    <div v-if="source.notification_channels.length > 0" class="mt-2 flex flex-wrap gap-1">
                        <span
                            v-for="ch in source.notification_channels"
                            :key="ch.id"
                            :class="['px-2 py-0.5 rounded text-xs font-medium', typeColors[ch.type] || 'bg-zinc-500/20 text-zinc-400']"
                        >{{ ch.name }}</span>
                    </div>
                </div>
            </GlassCard>
        </div>

        <EmptyState
            v-else
            title="No ingest sources yet"
            description="Create a source to start receiving events from your applications."
            icon="server"
            icon-color="emerald"
        >
            <template #action>
                <button @click="showCreateForm = true" class="btn-primary py-3 px-4 inline-block">
                    Create Your First Source
                </button>
            </template>
        </EmptyState>
    </div>

    <ConfirmDialog
        v-model:show="showRotateDialog"
        title="Rotate Token"
        :message="`Rotate the token for '${sourceToRotate?.name}'? The current token stops working immediately — every client still pushing with it will be rejected until it is updated.`"
        confirm-label="Rotate"
        variant="danger"
        @confirm="doRotate"
    />

    <ConfirmDialog
        v-model:show="showDeleteDialog"
        title="Delete Source"
        :message="`Are you sure you want to delete '${sourceToDelete?.name}'? All associated events will be deleted.`"
        confirm-label="Delete"
        variant="danger"
        @confirm="doDelete"
    />
</template>
