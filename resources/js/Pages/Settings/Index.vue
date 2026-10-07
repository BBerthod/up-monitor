<script setup lang="ts">
import { Head, useForm, usePage, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { usePushNotifications } from '@/Composables/usePushNotifications'
import PageHeader from '@/Components/PageHeader.vue'
import GlassCard from '@/Components/GlassCard.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import CopyButton from '@/Components/CopyButton.vue'
import PurgeDialog from '@/Components/PurgeDialog.vue'

interface Member { id: number; name: string; email: string; created_at: string }
interface Token { id: number; name: string; created_at: string; last_used_at: string | null }

const props = defineProps<{
    team: { id: number; name: string; sla_target: string }
    members: Member[]
    tokens: Token[]
    weeklyReportEnabled: boolean
}>()

const flash = computed(() => (usePage().props as any).flash)

const teamForm = useForm({ name: props.team.name })
const slaForm = useForm({ sla_target: props.team.sla_target })
const tokenForm = useForm({ name: '' })

const updateTeam = () => teamForm.put('/settings/team')
const updateSlaTarget = () => slaForm.put('/settings/team')
const createToken = () => tokenForm.post('/settings/tokens', { onSuccess: () => tokenForm.reset() })
const showRevokeDialog = ref(false)
const tokenToRevoke = ref<number | null>(null)

const confirmRevokeToken = (id: number) => {
    tokenToRevoke.value = id
    showRevokeDialog.value = true
}

const revokeToken = () => {
    if (tokenToRevoke.value) {
        router.delete(`/settings/tokens/${tokenToRevoke.value}`)
    }
}

const formatDate = (d: string) => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })

const newToken = ref<string | null>(null)

watch(flash, (val) => {
    if (val?.newToken) newToken.value = val.newToken
}, { immediate: true })

const dismissToken = () => { newToken.value = null }

const { isSupported, isSubscribed, isLoading, error, subscribe, unsubscribe } = usePushNotifications()

const weeklyReportForm = useForm({ enabled: props.weeklyReportEnabled })
const toggleWeeklyReport = () => {
    weeklyReportForm.enabled = !weeklyReportForm.enabled
    weeklyReportForm.post('/settings/weekly-report')
}

const showPurgeChecks = ref(false)
const showPurgeIncidents = ref(false)
const showPurgeLighthouse = ref(false)

const dataManagementItems = [
    {
        key: 'checks' as const,
        label: 'Check history',
        description: 'Response times and status logs from all monitors',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
        show: showPurgeChecks,
    },
    {
        key: 'incidents' as const,
        label: 'Incidents',
        description: 'Downtime incidents and their causes across all monitors',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',
        show: showPurgeIncidents,
    },
    {
        key: 'lighthouse' as const,
        label: 'Lighthouse scores',
        description: 'Performance audit history from all monitors',
        icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>',
        show: showPurgeLighthouse,
    },
]
</script>

<template>
    <Head title="Settings" />

    <div class="space-y-6">
        <PageHeader title="Settings" />

        <div v-if="newToken" class="p-4 rounded-lg bg-amber-500/10 border border-amber-500/20">
            <div class="flex items-center justify-between mb-2">
                <p class="text-amber-400 text-sm font-medium">Copy this token now. It won't be shown again.</p>
                <button @click="dismissToken" class="text-slate-500 hover:text-slate-300 transition-colors" aria-label="Dismiss token">&times;</button>
            </div>
            <div class="flex items-center gap-2">
                <input :value="newToken" readonly class="form-input w-full font-mono text-sm bg-white/5" />
                <CopyButton :text="newToken" size="sm" />
            </div>
        </div>

        <GlassCard title="Team Settings">
            <form @submit.prevent="updateTeam" class="flex items-end gap-4 mb-4">
                <div class="flex-1">
                    <label class="block text-sm text-slate-400 mb-1">Team Name</label>
                    <input v-model="teamForm.name" type="text" class="form-input w-full" />
                    <p v-if="teamForm.errors.name" class="text-red-400 text-sm mt-1">{{ teamForm.errors.name }}</p>
                </div>
                <button type="submit" class="btn-primary" :disabled="teamForm.processing">Save</button>
            </form>
            <form @submit.prevent="updateSlaTarget" class="flex items-end gap-4">
                <div class="flex-1">
                    <label class="block text-sm text-slate-400 mb-1">SLA target (%)</label>
                    <input
                        v-model="slaForm.sla_target"
                        type="number"
                        step="0.01"
                        min="90"
                        max="100"
                        class="form-input w-full"
                    />
                    <p v-if="slaForm.errors.sla_target" class="text-red-400 text-sm mt-1">{{ slaForm.errors.sla_target }}</p>
                </div>
                <button type="submit" class="btn-primary" :disabled="slaForm.processing">Save</button>
            </form>
        </GlassCard>

        <GlassCard title="Team Members">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead><tr class="text-left text-slate-400 text-sm border-b border-white/10"><th class="pb-3 font-medium">Name</th><th class="pb-3 font-medium">Email</th><th class="pb-3 font-medium">Joined</th></tr></thead>
                    <tbody>
                        <tr v-for="member in members" :key="member.id" class="border-b border-white/5 last:border-0">
                            <td class="py-3 text-white text-sm">{{ member.name }}</td>
                            <td class="py-3 text-slate-300 text-sm font-mono">{{ member.email }}</td>
                            <td class="py-3 text-slate-400 text-sm">{{ formatDate(member.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </GlassCard>

        <GlassCard title="API Tokens">
            <form @submit.prevent="createToken" class="flex items-end gap-4 mb-6">
                <div class="flex-1">
                    <label class="block text-sm text-slate-400 mb-1">Token Name</label>
                    <input v-model="tokenForm.name" type="text" class="form-input w-full" placeholder="e.g. CLI Access" />
                    <p v-if="tokenForm.errors.name" class="text-red-400 text-sm mt-1">{{ tokenForm.errors.name }}</p>
                </div>
                <button type="submit" title="Generate a new API token" class="btn-primary" :disabled="tokenForm.processing">Create Token</button>
            </form>

            <div v-if="tokens.length > 0" class="overflow-x-auto">
                <table class="w-full">
                    <thead><tr class="text-left text-slate-400 text-sm border-b border-white/10"><th class="pb-3 font-medium">Name</th><th class="pb-3 font-medium">Created</th><th class="pb-3 font-medium">Last Used</th><th class="pb-3 font-medium"></th></tr></thead>
                    <tbody>
                        <tr v-for="token in tokens" :key="token.id" class="border-b border-white/5 last:border-0">
                            <td class="py-3 text-white text-sm font-mono">{{ token.name }}</td>
                            <td class="py-3 text-slate-400 text-sm">{{ formatDate(token.created_at) }}</td>
                            <td class="py-3 text-slate-400 text-sm">{{ token.last_used_at ? formatDate(token.last_used_at) : 'Never' }}</td>
                            <td class="py-3 text-right">
                                <button @click="confirmRevokeToken(token.id)" title="Revoke this token permanently" class="text-red-400 hover:text-red-300 text-sm transition-colors">Revoke</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-slate-500 text-center py-4">No API tokens created yet</p>
        </GlassCard>

        <GlassCard title="Push Notifications">
            <p v-if="!isSupported" class="text-slate-500">Push notifications are not supported in this browser.</p>
            <template v-else>
                <div class="flex items-center justify-between p-4 rounded-lg border transition-colors" :class="isSubscribed ? 'bg-emerald-500/20 border-emerald-500/30' : 'bg-white/5 border-white/10'">
                    <div class="flex items-center gap-3">
                        <div class="w-2.5 h-2.5 rounded-full" :class="isSubscribed ? 'bg-emerald-400' : 'bg-slate-500'" />
                        <span class="text-white text-sm">{{ isSubscribed ? 'Enabled' : 'Disabled' }}</span>
                    </div>
                    <button @click="isSubscribed ? unsubscribe() : subscribe()" :disabled="isLoading" class="text-sm px-3 py-1.5 rounded transition-colors disabled:opacity-50" :class="isSubscribed ? 'bg-emerald-500/20 text-emerald-400 hover:bg-emerald-500/30' : 'bg-white/10 text-white hover:bg-white/20'">
                        <span v-if="isLoading" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            {{ isSubscribed ? 'Disabling...' : 'Enabling...' }}
                        </span>
                        <span v-else>{{ isSubscribed ? 'Disable' : 'Enable' }}</span>
                    </button>
                </div>
                <p v-if="error" class="text-red-400 text-sm mt-2">{{ error }}</p>
                <p class="text-slate-500 text-xs mt-3">Push notifications are per-browser. Enable on each device where you want to receive alerts.</p>
            </template>
        </GlassCard>

        <GlassCard title="Weekly Report">
            <div class="flex items-center justify-between p-4 rounded-lg border transition-colors"
                 :class="weeklyReportForm.enabled ? 'bg-emerald-500/20 border-emerald-500/30' : 'bg-white/5 border-white/10'">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="w-2.5 h-2.5 rounded-full" :class="weeklyReportForm.enabled ? 'bg-emerald-400' : 'bg-slate-500'" />
                        <span class="text-white text-sm">{{ weeklyReportForm.enabled ? 'Enabled' : 'Disabled' }}</span>
                    </div>
                    <p class="text-slate-500 text-xs mt-2 ml-[22px]">Receive a weekly uptime summary email every Monday at 8:00 AM.</p>
                </div>
                <button @click="toggleWeeklyReport"
                        :disabled="weeklyReportForm.processing"
                        class="text-sm px-3 py-1.5 rounded transition-colors disabled:opacity-50"
                        :class="weeklyReportForm.enabled
                            ? 'bg-emerald-500/20 text-emerald-400 hover:bg-emerald-500/30'
                            : 'bg-white/10 text-white hover:bg-white/20'">
                    <span v-if="weeklyReportForm.processing" class="flex items-center gap-2">
                        <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        Saving...
                    </span>
                    <span v-else>{{ weeklyReportForm.enabled ? 'Disable' : 'Enable' }}</span>
                </button>
            </div>
        </GlassCard>

        <GlassCard title="Data Management">
            <p class="text-sm text-slate-500 mb-4">Purge historical data across all monitors. This action is permanent and cannot be undone.</p>
            <div class="space-y-3">
                <div
                    v-for="item in dataManagementItems"
                    :key="item.key"
                    class="flex items-center justify-between p-3 rounded-lg bg-white/[0.03] border border-white/5"
                >
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="item.icon" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-white">{{ item.label }}</p>
                            <p class="text-xs text-slate-500">{{ item.description }}</p>
                        </div>
                    </div>
                    <button
                        @click="item.show.value = true"
                        class="px-3 py-1.5 rounded-lg text-xs text-red-400 hover:text-red-300 bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 transition-colors shrink-0"
                    >
                        Purge
                    </button>
                </div>
            </div>
        </GlassCard>

        <ConfirmDialog
            v-model:show="showRevokeDialog"
            title="Revoke Token"
            message="Are you sure you want to revoke this API token? Any applications using it will lose access."
            confirm-label="Revoke"
            variant="danger"
            @confirm="revokeToken"
        />

        <PurgeDialog v-model:show="showPurgeChecks" :monitor-id="null" target="checks" />
        <PurgeDialog v-model:show="showPurgeIncidents" :monitor-id="null" target="incidents" />
        <PurgeDialog v-model:show="showPurgeLighthouse" :monitor-id="null" target="lighthouse" />
    </div>
</template>
