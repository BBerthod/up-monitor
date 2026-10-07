<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import BackLink from '@/Components/BackLink.vue'
import PageHeader from '@/Components/PageHeader.vue'

interface ServerSettings {
    thresholds?: {
        disk_warning?: number | null
        disk_critical?: number | null
        ram_warning?: number | null
        ram_critical?: number | null
        cpu_warning?: number | null
        cpu_critical?: number | null
        cpu_sustained_points?: number | null
        load_cores?: number | null
        load_warning?: number | null
        load_critical?: number | null
    }
}

interface Server {
    id: number
    name: string
    dokploy_server_id: string | null
    is_active: boolean
    settings: ServerSettings
}

interface DefaultThresholds {
    disk_warning: number
    disk_critical: number
    ram_warning: number
    ram_critical: number
    cpu_warning: number
    cpu_critical: number
    cpu_sustained_points: number
    load_warning: number
    load_critical: number
}

const props = defineProps<{
    server: Server
    defaultThresholds: DefaultThresholds
}>()

const showThresholds = ref(
    Object.values(props.server.settings?.thresholds ?? {}).some((v) => v !== null && v !== undefined)
)

const existingThresholds = props.server.settings?.thresholds ?? {}

const form = useForm({
    name: props.server.name,
    dokploy_server_id: props.server.dokploy_server_id ?? '',
    is_active: props.server.is_active,
    settings: {
        thresholds: {
            disk_warning: (existingThresholds.disk_warning ?? null) as number | null,
            disk_critical: (existingThresholds.disk_critical ?? null) as number | null,
            ram_warning: (existingThresholds.ram_warning ?? null) as number | null,
            ram_critical: (existingThresholds.ram_critical ?? null) as number | null,
            cpu_warning: (existingThresholds.cpu_warning ?? null) as number | null,
            cpu_critical: (existingThresholds.cpu_critical ?? null) as number | null,
            cpu_sustained_points: (existingThresholds.cpu_sustained_points ?? null) as number | null,
            load_cores: (existingThresholds.load_cores ?? null) as number | null,
            load_warning: (existingThresholds.load_warning ?? null) as number | null,
            load_critical: (existingThresholds.load_critical ?? null) as number | null,
        },
    },
})

const submit = () => form.put(route('servers.update', props.server.id))
</script>

<template>
    <Head :title="`Edit ${server.name}`" />

    <div class="max-w-2xl mx-auto space-y-6">
        <BackLink :href="route('servers.show', server.id)" label="Back to Server" />
        <PageHeader
            :title="`Edit ${server.name}`"
            description="Update server configuration and alert thresholds."
        />

        <form @submit.prevent="submit" class="space-y-5">
            <!-- Name -->
            <div>
                <label class="block text-xs font-medium text-zinc-400 mb-1.5">
                    Server name <span class="text-red-400">*</span>
                </label>
                <input
                    v-model="form.name"
                    type="text"
                    required
                    class="w-full bg-transparent border border-white/10 rounded-lg px-3 py-2.5 text-sm text-white placeholder-zinc-600 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none transition-colors"
                />
                <p v-if="form.errors.name" class="mt-1.5 text-xs text-red-400">{{ form.errors.name }}</p>
            </div>

            <!-- Dokploy server ID -->
            <div>
                <label class="block text-xs font-medium text-zinc-400 mb-1.5">
                    Dokploy server ID <span class="text-zinc-600">(optional)</span>
                </label>
                <input
                    v-model="form.dokploy_server_id"
                    type="text"
                    placeholder="e.g. srv_abc123"
                    class="w-full bg-transparent border border-white/10 rounded-lg px-3 py-2.5 text-sm text-white placeholder-zinc-600 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 outline-none transition-colors"
                />
                <p v-if="form.errors.dokploy_server_id" class="mt-1.5 text-xs text-red-400">{{ form.errors.dokploy_server_id }}</p>
            </div>

            <!-- Active toggle -->
            <div>
                <label class="flex items-center gap-3 cursor-pointer group">
                    <div class="relative">
                        <input v-model="form.is_active" type="checkbox" class="sr-only peer" />
                        <div class="w-9 h-5 rounded-full transition-colors bg-zinc-700 peer-checked:bg-emerald-500"></div>
                        <div class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white transition-transform peer-checked:translate-x-4"></div>
                    </div>
                    <span class="text-sm text-zinc-300 group-hover:text-white transition-colors">Active</span>
                </label>
            </div>

            <!-- Alert thresholds (collapsible) -->
            <div class="border border-white/5 rounded-xl overflow-hidden">
                <button
                    type="button"
                    @click="showThresholds = !showThresholds"
                    class="w-full flex items-center justify-between px-4 py-3 text-sm text-zinc-400 hover:text-white hover:bg-white/[0.02] transition-colors"
                >
                    <span class="font-medium">
                        Alert thresholds
                        <span
                            v-if="Object.values(existingThresholds).some(v => v !== null && v !== undefined)"
                            class="ml-2 px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-500/10 text-amber-400 border border-amber-500/20"
                        >overridden</span>
                        <span v-else class="text-zinc-600">(optional override)</span>
                    </span>
                    <svg
                        class="w-4 h-4 transition-transform"
                        :class="showThresholds ? 'rotate-180' : ''"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    >
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </button>

                <div v-if="showThresholds" class="px-4 pb-4 pt-2 border-t border-white/5 space-y-4 bg-white/[0.01]">
                    <p class="text-xs text-zinc-600">
                        Leave fields empty to use global defaults.
                        Global defaults: CPU {{ defaultThresholds.cpu_warning }}% / {{ defaultThresholds.cpu_critical }}%,
                        RAM {{ defaultThresholds.ram_warning }}% / {{ defaultThresholds.ram_critical }}%,
                        Disk {{ defaultThresholds.disk_warning }}% / {{ defaultThresholds.disk_critical }}%.
                    </p>

                    <!-- CPU -->
                    <div>
                        <span class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">CPU</span>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] text-zinc-500 mb-1">Warning %</label>
                                <input
                                    v-model.number="form.settings.thresholds.cpu_warning"
                                    type="number" min="1" max="99"
                                    :placeholder="`${defaultThresholds.cpu_warning}`"
                                    class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-amber-500/50 focus:ring-1 focus:ring-amber-500/30 outline-none transition-colors"
                                />
                            </div>
                            <div>
                                <label class="block text-[11px] text-zinc-500 mb-1">Critical %</label>
                                <input
                                    v-model.number="form.settings.thresholds.cpu_critical"
                                    type="number" min="1" max="100"
                                    :placeholder="`${defaultThresholds.cpu_critical}`"
                                    class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-red-500/50 focus:ring-1 focus:ring-red-500/30 outline-none transition-colors"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- RAM -->
                    <div>
                        <span class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">RAM</span>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] text-zinc-500 mb-1">Warning %</label>
                                <input
                                    v-model.number="form.settings.thresholds.ram_warning"
                                    type="number" min="1" max="99"
                                    :placeholder="`${defaultThresholds.ram_warning}`"
                                    class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-amber-500/50 focus:ring-1 focus:ring-amber-500/30 outline-none transition-colors"
                                />
                            </div>
                            <div>
                                <label class="block text-[11px] text-zinc-500 mb-1">Critical %</label>
                                <input
                                    v-model.number="form.settings.thresholds.ram_critical"
                                    type="number" min="1" max="100"
                                    :placeholder="`${defaultThresholds.ram_critical}`"
                                    class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-red-500/50 focus:ring-1 focus:ring-red-500/30 outline-none transition-colors"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Disk -->
                    <div>
                        <span class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Disk</span>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] text-zinc-500 mb-1">Warning %</label>
                                <input
                                    v-model.number="form.settings.thresholds.disk_warning"
                                    type="number" min="1" max="99"
                                    :placeholder="`${defaultThresholds.disk_warning}`"
                                    class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-amber-500/50 focus:ring-1 focus:ring-amber-500/30 outline-none transition-colors"
                                />
                            </div>
                            <div>
                                <label class="block text-[11px] text-zinc-500 mb-1">Critical %</label>
                                <input
                                    v-model.number="form.settings.thresholds.disk_critical"
                                    type="number" min="1" max="100"
                                    :placeholder="`${defaultThresholds.disk_critical}`"
                                    class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-red-500/50 focus:ring-1 focus:ring-red-500/30 outline-none transition-colors"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- CPU sustained points -->
                    <div>
                        <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">CPU Sustained Points</label>
                        <input
                            v-model.number="form.settings.thresholds.cpu_sustained_points"
                            type="number" min="1"
                            :placeholder="`${defaultThresholds.cpu_sustained_points}`"
                            class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-emerald-500/50 focus:ring-1 focus:ring-emerald-500/30 outline-none transition-colors"
                        />
                        <p class="mt-1 text-[11px] text-zinc-600">Consecutive data points above CPU critical before alerting.</p>
                    </div>

                    <!-- Server load -->
                    <div>
                        <span class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Server load</span>
                        <div class="mb-3">
                            <label class="block text-[11px] text-zinc-500 mb-1">CPU cores</label>
                            <input
                                v-model.number="form.settings.thresholds.load_cores"
                                type="number" min="1" max="1024" step="1"
                                placeholder="e.g. 64"
                                class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-emerald-500/50 focus:ring-1 focus:ring-emerald-500/30 outline-none transition-colors"
                            />
                            <p class="mt-1 text-[11px] text-zinc-600">Number of CPU cores — required to enable load alerting (e.g. 64). Leave empty to disable load alerts.</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] text-zinc-500 mb-1">Warning ratio</label>
                                <input
                                    v-model.number="form.settings.thresholds.load_warning"
                                    type="number" step="0.05" min="0" max="100"
                                    :placeholder="`${defaultThresholds.load_warning}`"
                                    class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-amber-500/50 focus:ring-1 focus:ring-amber-500/30 outline-none transition-colors"
                                />
                            </div>
                            <div>
                                <label class="block text-[11px] text-zinc-500 mb-1">Critical ratio</label>
                                <input
                                    v-model.number="form.settings.thresholds.load_critical"
                                    type="number" step="0.05" min="0" max="100"
                                    :placeholder="`${defaultThresholds.load_critical}`"
                                    class="w-full bg-transparent border border-white/10 rounded-md px-3 py-2 text-sm text-white placeholder-zinc-700 focus:border-red-500/50 focus:ring-1 focus:ring-red-500/30 outline-none transition-colors"
                                />
                            </div>
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-600">load_avg_1 / cores ratio, e.g. 0.85 = warn when load reaches 85% of core count.</p>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="flex items-center gap-3 pt-1">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="btn-primary flex items-center gap-2 px-5 py-2.5 disabled:opacity-60 disabled:cursor-not-allowed"
                >
                    <svg v-if="form.processing" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                    </svg>
                    Save Changes
                </button>
                <a :href="route('servers.show', server.id)" class="text-sm text-zinc-500 hover:text-white transition-colors px-3 py-2">Cancel</a>
            </div>
        </form>
    </div>
</template>
