<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import BackLink from '@/Components/BackLink.vue'
import PageHeader from '@/Components/PageHeader.vue'

const props = defineProps<{
    frequencies: Array<{ value: number; label: string }>
    modes: Array<{ value: string; label: string }>
    monitors: Array<{ id: number; name: string; url: string }>
    blockedHeaders: string[]
}>()

const form = useForm({
    name: '',
    domain: '',
    mode: 'urls',
    sitemap_url: '',
    urls: [''] as string[],
    frequency_minutes: 60,
    max_urls: 50,
    timeout_seconds: 10,
    custom_headers: [] as Array<{ key: string; value: string }>,
    monitor_id: null as number | null,
})

// Textarea binding for the URLs field (one per line)
const urlText = ref('')

// Custom headers section visibility
const showCustomHeaders = ref(false)

// Auto-fill sitemap URL when domain changes and mode is sitemap
watch(() => form.domain, (domain) => {
    if (form.mode === 'sitemap' && domain) {
        const cleaned = domain.replace(/^https?:\/\//, '').replace(/\/$/, '')
        form.sitemap_url = `https://${cleaned}/sitemap.xml`
    }
})

watch(() => form.mode, (mode) => {
    if (mode === 'sitemap' && form.domain) {
        const cleaned = form.domain.replace(/^https?:\/\//, '').replace(/\/$/, '')
        form.sitemap_url = `https://${cleaned}/sitemap.xml`
    }
})

const addHeader = () => {
    if (form.custom_headers.length < 10) {
        form.custom_headers.push({ key: '', value: '' })
    }
}

const removeHeader = (index: number) => {
    form.custom_headers.splice(index, 1)
}

const submit = () => {
    if (form.mode === 'urls') {
        form.urls = urlText.value.split('\n').map(u => u.trim()).filter(Boolean)
    }

    const headersObject: Record<string, string> = {}
    form.custom_headers.forEach(h => {
        if (h.key.trim()) {
            headersObject[h.key.trim()] = h.value
        }
    })

    form.transform(data => ({
        ...data,
        custom_headers: Object.keys(headersObject).length > 0 ? headersObject : null,
    }))

    form.post(route('warming.store'))
}

const modeInfo: Record<string, { label: string; description: string }> = {
    sitemap: { label: 'Sitemap', description: 'Crawl and warm URLs from your XML sitemap' },
    urls: { label: 'URL List', description: 'Warm a custom list of specific URLs' },
}
</script>

<template>
    <Head title="Create Warm Site" />

    <div class="max-w-2xl mx-auto space-y-6">
        <BackLink :href="route('warming.index')" label="Back to Cache Warming" />
        <PageHeader title="Create Warm Site" description="Configure a new cache warming target." />

        <form @submit.prevent="submit" class="glass p-6 space-y-6">
            <!-- Mode selector -->
            <div>
                <label class="block text-sm font-medium text-white mb-3">Warming Mode</label>
                <div class="grid grid-cols-2 gap-3">
                    <div
                        v-for="m in modes"
                        :key="m.value"
                        @click="form.mode = m.value"
                        :class="['p-3 rounded-lg cursor-pointer transition-colors border text-center',
                            form.mode === m.value
                                ? 'bg-emerald-500/20 border-emerald-500/30'
                                : 'bg-white/5 border-white/10 hover:bg-white/10']"
                    >
                        <p class="text-white text-sm font-medium">{{ modeInfo[m.value]?.label ?? m.label }}</p>
                        <p class="text-slate-500 text-xs mt-0.5">{{ modeInfo[m.value]?.description ?? '' }}</p>
                    </div>
                </div>
                <p v-if="form.errors.mode" class="text-sm text-red-400 mt-1">{{ form.errors.mode }}</p>
            </div>

            <!-- Name -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">Name</label>
                <input v-model="form.name" type="text" class="form-input w-full" placeholder="My Website" required />
                <p v-if="form.errors.name" class="text-sm text-red-400 mt-1">{{ form.errors.name }}</p>
            </div>

            <!-- Domain -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">Domain</label>
                <input v-model="form.domain" type="text" class="form-input w-full" placeholder="example.com" required />
                <p class="text-xs text-slate-500 mt-1">Without protocol prefix, e.g. example.com</p>
                <p v-if="form.errors.domain" class="text-sm text-red-400 mt-1">{{ form.errors.domain }}</p>
            </div>

            <!-- Link to Monitor (optional) -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">Link to Monitor <span class="text-slate-500 font-normal">(optional)</span></label>
                <select v-model="form.monitor_id" class="form-input w-full">
                    <option :value="null">None</option>
                    <option v-for="m in monitors" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <p class="text-xs text-slate-500 mt-1">Associate this warm site with an existing monitor</p>
                <p v-if="form.errors.monitor_id" class="text-sm text-red-400 mt-1">{{ form.errors.monitor_id }}</p>
            </div>

            <!-- Sitemap URL (mode=sitemap) -->
            <div v-if="form.mode === 'sitemap'">
                <label class="block text-sm font-medium text-white mb-2">Sitemap URL</label>
                <input v-model="form.sitemap_url" type="url" class="form-input w-full" placeholder="https://example.com/sitemap.xml" required />
                <p class="text-xs text-slate-500 mt-1">Auto-filled from domain — change if your sitemap is at a custom path</p>
                <p v-if="form.errors.sitemap_url" class="text-sm text-red-400 mt-1">{{ form.errors.sitemap_url }}</p>
            </div>

            <!-- URLs textarea (mode=urls) -->
            <div v-if="form.mode === 'urls'">
                <label class="block text-sm font-medium text-white mb-2">URLs <span class="text-slate-500 font-normal">(one per line)</span></label>
                <textarea
                    v-model="urlText"
                    rows="6"
                    class="form-input w-full font-mono text-sm resize-y"
                    placeholder="https://example.com/&#10;https://example.com/about&#10;https://example.com/products"
                />
                <p class="text-xs text-slate-500 mt-1">Enter one full URL per line</p>
                <p v-if="form.errors.urls" class="text-sm text-red-400 mt-1">{{ form.errors.urls }}</p>
            </div>

            <!-- Frequency -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">Frequency</label>
                <select v-model.number="form.frequency_minutes" class="form-input w-full">
                    <option v-for="f in frequencies" :key="f.value" :value="f.value">{{ f.label }}</option>
                </select>
                <p v-if="form.errors.frequency_minutes" class="text-sm text-red-400 mt-1">{{ form.errors.frequency_minutes }}</p>
            </div>

            <!-- Max URLs -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">Max URLs per run</label>
                <input v-model.number="form.max_urls" type="number" min="1" max="500" class="form-input w-full" placeholder="50" />
                <p class="text-xs text-slate-500 mt-1">Limit how many URLs are warmed per scheduled run</p>
                <p v-if="form.errors.max_urls" class="text-sm text-red-400 mt-1">{{ form.errors.max_urls }}</p>
            </div>

            <!-- Request Timeout -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">Request timeout (seconds)</label>
                <input v-model.number="form.timeout_seconds" type="number" min="5" max="60" class="form-input w-full" placeholder="10" />
                <p class="text-xs text-slate-500 mt-1">Max wait per URL before marking it as an error (5–60 s). Increase for sites with slow cold renders.</p>
                <p v-if="form.errors.timeout_seconds" class="text-sm text-red-400 mt-1">{{ form.errors.timeout_seconds }}</p>
            </div>

            <!-- Custom Headers -->
            <div>
                <button
                    type="button"
                    @click="showCustomHeaders = !showCustomHeaders"
                    class="flex items-center gap-2 text-sm text-slate-400 hover:text-white transition-colors"
                >
                    <svg
                        :class="['w-4 h-4 transition-transform', showCustomHeaders ? 'rotate-90' : '']"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    Custom Headers
                    <span v-if="form.custom_headers.length > 0" class="text-xs text-emerald-400">({{ form.custom_headers.length }})</span>
                </button>

                <div v-if="showCustomHeaders" class="mt-3 space-y-3">
                    <p class="text-xs text-slate-500">
                        Add custom HTTP headers sent with each warming request (max 10).
                        The following headers are blocked for security:
                        <span class="font-mono text-slate-400">{{ blockedHeaders.join(', ') }}</span>
                    </p>

                    <div v-for="(header, index) in form.custom_headers" :key="index" class="flex gap-2 items-start">
                        <input
                            v-model="header.key"
                            type="text"
                            class="form-input flex-1"
                            placeholder="Header name"
                        />
                        <input
                            v-model="header.value"
                            type="text"
                            class="form-input flex-1"
                            placeholder="Value"
                        />
                        <button
                            type="button"
                            @click="removeHeader(index)"
                            class="p-2 text-slate-500 hover:text-red-400 transition-colors shrink-0"
                            title="Remove header"
                        >
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <p v-if="form.errors['custom_headers']" class="text-sm text-red-400">{{ form.errors['custom_headers'] }}</p>

                    <button
                        v-if="form.custom_headers.length < 10"
                        type="button"
                        @click="addHeader"
                        class="text-sm text-emerald-400 hover:text-emerald-300 transition-colors flex items-center gap-1"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Header
                    </button>
                </div>
            </div>

            <button type="submit" :disabled="form.processing" class="btn-primary w-full py-3 px-4 disabled:opacity-50">
                {{ form.processing ? 'Creating...' : 'Create Warm Site' }}
            </button>
        </form>
    </div>
</template>
