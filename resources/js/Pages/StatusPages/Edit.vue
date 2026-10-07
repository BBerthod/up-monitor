<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import BackLink from '@/Components/BackLink.vue'
import PageHeader from '@/Components/PageHeader.vue'
import { computed } from 'vue'

interface Monitor { id: number; name: string; url: string }

const props = defineProps<{
    statusPage: { id: number; name: string; slug: string; description: string | null; is_active: boolean; theme: string; monitor_ids: number[] }
    monitors: Monitor[]
}>()

const form = useForm({
    name: props.statusPage.name,
    slug: props.statusPage.slug,
    description: props.statusPage.description || '',
    is_active: props.statusPage.is_active,
    theme: props.statusPage.theme,
    monitors: [...props.statusPage.monitor_ids],
})

const toggleMonitor = (id: number) => {
    const i = form.monitors.indexOf(id)
    i === -1 ? form.monitors.push(id) : form.monitors.splice(i, 1)
}

const selectedMonitors = computed(() =>
    form.monitors.map(id => props.monitors.find(m => m.id === id)).filter(Boolean) as Monitor[]
)

const moveUp = (i: number) => { if (i > 0) [form.monitors[i], form.monitors[i - 1]] = [form.monitors[i - 1], form.monitors[i]] }
const moveDown = (i: number) => { if (i < form.monitors.length - 1) [form.monitors[i], form.monitors[i + 1]] = [form.monitors[i + 1], form.monitors[i]] }

const submit = () => form.put(route('status-pages.update', props.statusPage.id))
</script>

<template>
    <Head :title="'Edit ' + statusPage.name" />

    <div class="max-w-2xl mx-auto space-y-6">
        <BackLink :href="route('status-pages.index')" label="Back to Status Pages" />
        <PageHeader title="Edit Status Page" :description="`Update settings for ${statusPage.name}.`" />

        <form @submit.prevent="submit" class="glass p-6 space-y-6">
            <div>
                <label class="block text-sm font-medium text-white mb-2">Name</label>
                <input v-model="form.name" type="text" class="form-input w-full" required />
                <p v-if="form.errors.name" class="text-sm text-red-400 mt-1">{{ form.errors.name }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-white mb-2">Slug</label>
                <div class="flex items-center gap-2">
                    <span class="text-slate-500 text-sm">/status/</span>
                    <input v-model="form.slug" type="text" class="form-input flex-1" required />
                </div>
                <p v-if="form.errors.slug" class="text-sm text-red-400 mt-1">{{ form.errors.slug }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-white mb-2">Description <span class="text-slate-500 font-normal">(optional)</span></label>
                <textarea v-model="form.description" rows="2" class="form-input w-full" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Theme</label>
                    <select v-model="form.theme" class="form-input w-full">
                        <option value="dark">Dark</option>
                        <option value="light">Light</option>
                    </select>
                </div>
                <div class="flex items-end pb-1">
                    <div class="flex items-center gap-3">
                        <button type="button" @click="form.is_active = !form.is_active"
                            :class="['relative inline-flex h-6 w-11 rounded-full transition-colors', form.is_active ? 'bg-emerald-500' : 'bg-slate-600']">
                            <span :class="['inline-block h-5 w-5 transform rounded-full bg-white shadow transition', form.is_active ? 'translate-x-5' : 'translate-x-0']" />
                        </button>
                        <span class="text-sm text-slate-300">Active</span>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-white mb-3">Monitors</label>
                <div v-if="monitors.length > 0" class="space-y-2">
                    <div v-for="m in monitors" :key="m.id" @click="toggleMonitor(m.id)"
                        :class="['flex items-center gap-3 p-3 rounded-lg cursor-pointer transition-colors border',
                            form.monitors.includes(m.id) ? 'bg-emerald-500/20 border-emerald-500/30' : 'bg-white/5 border-white/10 hover:bg-white/10']">
                        <div class="flex-1">
                            <p class="text-white text-sm font-medium">{{ m.name }}</p>
                            <p class="text-slate-500 text-xs">{{ m.url }}</p>
                        </div>
                        <div :class="['w-5 h-5 rounded border-2 flex items-center justify-center', form.monitors.includes(m.id) ? 'bg-emerald-500 border-emerald-500' : 'border-slate-500']">
                            <svg v-if="form.monitors.includes(m.id)" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
                        </div>
                    </div>
                </div>

                <div v-if="selectedMonitors.length > 1" class="mt-4 space-y-1">
                    <p class="text-xs text-slate-400 mb-2">Display order:</p>
                    <div v-for="(m, i) in selectedMonitors" :key="m.id" class="flex items-center gap-2 px-3 py-1.5 rounded bg-white/5 text-sm text-slate-300">
                        <span class="text-slate-500 w-4">{{ i + 1 }}.</span>
                        <span class="flex-1">{{ m.name }}</span>
                        <button type="button" @click.stop="moveUp(i)" :disabled="i === 0" class="text-slate-500 hover:text-white disabled:opacity-30">&#x25B2;</button>
                        <button type="button" @click.stop="moveDown(i)" :disabled="i === selectedMonitors.length - 1" class="text-slate-500 hover:text-white disabled:opacity-30">&#x25BC;</button>
                    </div>
                </div>
            </div>

            <button type="submit" :disabled="form.processing" class="btn-primary w-full py-3 px-4 disabled:opacity-50">
                {{ form.processing ? 'Updating...' : 'Update Status Page' }}
            </button>
        </form>
    </div>
</template>
