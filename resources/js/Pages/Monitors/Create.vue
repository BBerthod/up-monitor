<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import BackLink from '@/Components/BackLink.vue'
import PageHeader from '@/Components/PageHeader.vue'

const props = defineProps<{
    notificationChannels: Array<{ id: number; name: string; type: string }>
}>()

const form = useForm({
    name: '',
    type: 'http',
    url: '',
    method: 'GET',
    expected_status_code: 200,
    keyword: '',
    follow_redirects: true,
    redirect_location_keyword: '',
    request_headers: [] as Array<{ key: string; value: string }>,
    port: null as number | null,
    dns_record_type: 'A',
    dns_expected_value: '',
    interval: 1,
    warning_threshold_ms: null as number | null,
    critical_threshold_ms: null as number | null,
    alert_after_failures: 3,
    notification_channels: [] as number[],
})

// Custom request headers section visibility
const showRequestHeaders = ref(false)

const addRequestHeader = () => {
    if (form.request_headers.length < 10) {
        form.request_headers.push({ key: '', value: '' })
    }
}

const removeRequestHeader = (index: number) => {
    form.request_headers.splice(index, 1)
}

const submit = () => {
    const headersObject: Record<string, string> = {}
    form.request_headers.forEach(h => {
        if (h.key.trim()) {
            headersObject[h.key.trim()] = h.value
        }
    })

    form.transform(data => ({
        ...data,
        request_headers: Object.keys(headersObject).length > 0 ? headersObject : null,
    }))

    form.post(route('monitors.store'))
}

const toggleChannel = (id: number) => {
    const i = form.notification_channels.indexOf(id)
    i === -1 ? form.notification_channels.push(id) : form.notification_channels.splice(i, 1)
}

const portPresets = [
    { label: 'HTTP (80)', value: 80 },
    { label: 'HTTPS (443)', value: 443 },
    { label: 'SSH (22)', value: 22 },
    { label: 'FTP (21)', value: 21 },
    { label: 'SMTP (25)', value: 25 },
    { label: 'SMTP (587)', value: 587 },
    { label: 'MySQL (3306)', value: 3306 },
    { label: 'PostgreSQL (5432)', value: 5432 },
    { label: 'Redis (6379)', value: 6379 },
]

const dnsRecordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA', 'SRV']

const typeLabels: Record<string, { label: string; description: string }> = {
    http: { label: 'HTTP(S)', description: 'Monitor website availability and response' },
    ping: { label: 'Ping (ICMP)', description: 'Check if a host is reachable' },
    port: { label: 'TCP Port', description: 'Verify a service is listening on a port' },
    dns: { label: 'DNS Record', description: 'Validate DNS record values' },
}

const urlPlaceholder = () => {
    switch (form.type) {
        case 'ping': return '192.168.1.1 or example.com'
        case 'port': return 'example.com'
        case 'dns': return 'example.com'
        default: return 'https://example.com'
    }
}

const urlLabel = () => form.type === 'http' ? 'URL' : 'Host / Domain'
</script>

<template>
    <Head title="Create Monitor" />

    <div class="max-w-2xl mx-auto space-y-6">
        <BackLink :href="route('monitors.index')" label="Back to Monitors" />
        <PageHeader title="Create Monitor" description="Set up a new uptime monitor for your service." />

        <form @submit.prevent="submit" class="glass p-6 space-y-6">
            <!-- Monitor Type -->
            <div>
                <label class="block text-sm font-medium text-white mb-3">Monitor Type</label>
                <div class="grid grid-cols-2 gap-3">
                    <div v-for="(info, key) in typeLabels" :key="key" @click="form.type = key"
                        :class="['p-3 rounded-lg cursor-pointer transition-colors border text-center',
                            form.type === key ? 'bg-emerald-500/20 border-emerald-500/30' : 'bg-white/5 border-white/10 hover:bg-white/10']">
                        <p class="text-white text-sm font-medium">{{ info.label }}</p>
                        <p class="text-slate-500 text-xs mt-0.5">{{ info.description }}</p>
                    </div>
                </div>
                <p v-if="form.errors.type" class="text-sm text-red-400 mt-1">{{ form.errors.type }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-white mb-2">Name</label>
                <input v-model="form.name" type="text" class="form-input w-full" placeholder="My Website" required />
                <p v-if="form.errors.name" class="text-sm text-red-400 mt-1">{{ form.errors.name }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-white mb-2">{{ urlLabel() }}</label>
                <input v-model="form.url" :type="form.type === 'http' ? 'url' : 'text'" class="form-input w-full" :placeholder="urlPlaceholder()" required />
                <p v-if="form.errors.url" class="text-sm text-red-400 mt-1">{{ form.errors.url }}</p>
            </div>

            <!-- HTTP-specific fields -->
            <template v-if="form.type === 'http'">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">Method</label>
                        <select v-model="form.method" class="form-input w-full">
                            <option value="GET">GET</option><option value="POST">POST</option><option value="HEAD">HEAD</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">Expected Status</label>
                        <input v-model.number="form.expected_status_code" type="number" class="form-input w-full" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-white mb-2">Keyword <span class="text-slate-500 font-normal">(optional)</span></label>
                    <input v-model="form.keyword" type="text" class="form-input w-full" placeholder="e.g. Welcome" />
                    <p class="text-xs text-slate-500 mt-1">Check response body for this string</p>
                </div>

                <div>
                    <label class="flex items-center gap-3 cursor-pointer group">
                        <div class="relative">
                            <input v-model="form.follow_redirects" type="checkbox" class="sr-only peer" />
                            <div class="w-9 h-5 rounded-full transition-colors bg-zinc-700 peer-checked:bg-emerald-500"></div>
                            <div class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white transition-transform peer-checked:translate-x-4"></div>
                        </div>
                        <span class="text-sm text-zinc-300 group-hover:text-white transition-colors">Follow redirects</span>
                    </label>
                    <p class="text-xs text-slate-500 mt-1">Disable to verify a 3xx response directly (e.g. affiliate redirect guard on /go/).</p>
                    <p v-if="form.errors.follow_redirects" class="text-sm text-red-400 mt-1">{{ form.errors.follow_redirects }}</p>
                </div>

                <!-- Custom Request Headers -->
                <div>
                    <button
                        type="button"
                        @click="showRequestHeaders = !showRequestHeaders"
                        class="flex items-center gap-2 text-sm text-slate-400 hover:text-white transition-colors"
                    >
                        <svg
                            :class="['w-4 h-4 transition-transform', showRequestHeaders ? 'rotate-90' : '']"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                        Custom request headers
                        <span v-if="form.request_headers.length > 0" class="text-xs text-emerald-400">({{ form.request_headers.length }})</span>
                    </button>

                    <div v-if="showRequestHeaders" class="mt-3 space-y-3">
                        <p class="text-xs text-slate-500">e.g. Referer for affiliate redirect guards (max 10). Host, Content-Length and Authorization cannot be overridden.</p>

                        <div v-for="(header, index) in form.request_headers" :key="index" class="flex gap-2 items-start">
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
                                @click="removeRequestHeader(index)"
                                class="p-2 text-slate-500 hover:text-red-400 transition-colors shrink-0"
                                title="Remove header"
                            >
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <p v-if="form.errors['request_headers']" class="text-sm text-red-400">{{ form.errors['request_headers'] }}</p>

                        <button
                            v-if="form.request_headers.length < 10"
                            type="button"
                            @click="addRequestHeader"
                            class="text-sm text-emerald-400 hover:text-emerald-300 transition-colors flex items-center gap-1"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Add Header
                        </button>
                    </div>
                </div>

                <div v-if="!form.follow_redirects">
                    <label class="block text-sm font-medium text-white mb-2">Redirect location keyword <span class="text-slate-500 font-normal">(optional)</span></label>
                    <input v-model="form.redirect_location_keyword" type="text" class="form-input w-full" placeholder="e.g. amazon.fr/dp/" />
                    <p class="text-xs text-slate-500 mt-1">When redirect following is disabled and the response is 3xx, the Location header must contain this string.</p>
                    <p v-if="form.errors.redirect_location_keyword" class="text-sm text-red-400 mt-1">{{ form.errors.redirect_location_keyword }}</p>
                </div>
            </template>

            <!-- Port-specific fields -->
            <template v-if="form.type === 'port'">
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Port</label>
                    <div class="flex gap-3">
                        <input v-model.number="form.port" type="number" min="1" max="65535" class="form-input flex-1" placeholder="443" required />
                        <select @change="form.port = Number(($event.target as HTMLSelectElement).value)" class="form-input w-48">
                            <option value="">Presets...</option>
                            <option v-for="p in portPresets" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                    </div>
                    <p v-if="form.errors.port" class="text-sm text-red-400 mt-1">{{ form.errors.port }}</p>
                </div>
            </template>

            <!-- DNS-specific fields -->
            <template v-if="form.type === 'dns'">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">Record Type</label>
                        <select v-model="form.dns_record_type" class="form-input w-full">
                            <option v-for="t in dnsRecordTypes" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <p v-if="form.errors.dns_record_type" class="text-sm text-red-400 mt-1">{{ form.errors.dns_record_type }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">Expected Value</label>
                        <input v-model="form.dns_expected_value" type="text" class="form-input w-full" placeholder="93.184.216.34" required />
                        <p v-if="form.errors.dns_expected_value" class="text-sm text-red-400 mt-1">{{ form.errors.dns_expected_value }}</p>
                    </div>
                </div>
            </template>

            <div>
                <label class="block text-sm font-medium text-white mb-2">Check Interval</label>
                <select v-model.number="form.interval" class="form-input w-full">
                    <option v-for="v in [1,2,3,5,10,15,30,60]" :key="v" :value="v">Every {{ v }} minute{{ v > 1 ? 's' : '' }}</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Warning Threshold <span class="text-slate-500 font-normal">(ms)</span></label>
                    <input v-model.number="form.warning_threshold_ms" type="number" class="form-input w-full" placeholder="e.g. 1000" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Critical Threshold <span class="text-slate-500 font-normal">(ms)</span></label>
                    <input v-model.number="form.critical_threshold_ms" type="number" class="form-input w-full" placeholder="e.g. 3000" />
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-white mb-2">Alert after <span class="text-slate-500 font-normal">consecutive failures</span></label>
                <input v-model.number="form.alert_after_failures" type="number" min="1" max="10" class="form-input w-full" />
                <p class="text-xs text-slate-500 mt-1">Number of consecutive failures before a notification is sent. The incident is always recorded from the first failure.</p>
                <p v-if="form.errors.alert_after_failures" class="text-sm text-red-400 mt-1">{{ form.errors.alert_after_failures }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-white mb-3">Notification Channels</label>
                <div v-if="notificationChannels.length > 0" class="space-y-2">
                    <div v-for="ch in notificationChannels" :key="ch.id" @click="toggleChannel(ch.id)"
                        :class="['flex items-center gap-3 p-3 rounded-lg cursor-pointer transition-colors border',
                            form.notification_channels.includes(ch.id) ? 'bg-emerald-500/20 border-emerald-500/30' : 'bg-white/5 border-white/10 hover:bg-white/10']">
                        <div class="flex-1"><p class="text-white text-sm font-medium">{{ ch.name }}</p><p class="text-slate-500 text-xs uppercase">{{ ch.type }}</p></div>
                        <div :class="['w-5 h-5 rounded border-2 flex items-center justify-center', form.notification_channels.includes(ch.id) ? 'bg-emerald-500 border-emerald-500' : 'border-slate-500']">
                            <svg v-if="form.notification_channels.includes(ch.id)" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
                        </div>
                    </div>
                </div>
                <p v-else class="text-slate-500 text-sm py-4 text-center bg-white/5 rounded-lg">No notification channels available</p>
            </div>

            <button type="submit" :disabled="form.processing" class="btn-primary w-full py-3 px-4 disabled:opacity-50">
                {{ form.processing ? 'Creating...' : 'Create Monitor' }}
            </button>
        </form>
    </div>
</template>
