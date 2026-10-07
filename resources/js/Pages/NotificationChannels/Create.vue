<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import BackLink from '@/Components/BackLink.vue'
import PageHeader from '@/Components/PageHeader.vue'
import { computed, watch } from 'vue'

const form = useForm({
    name: '',
    type: 'email',
    settings: {} as Record<string, string>,
    is_active: true,
})

const types = [
    { value: 'email', label: 'Email' },
    { value: 'discord', label: 'Discord' },
    { value: 'telegram', label: 'Telegram' },
    { value: 'slack', label: 'Slack' },
    { value: 'webhook', label: 'Webhook' },
    { value: 'push', label: 'Push' },
]

const settingsFields: Record<string, { key: string; label: string; placeholder: string; sensitive?: boolean }[]> = {
    email: [{ key: 'recipients', label: 'Recipients', placeholder: 'user@example.com, other@example.com' }],
    webhook: [{ key: 'url', label: 'Webhook URL', placeholder: 'https://example.com/webhook', sensitive: true }],
    slack: [{ key: 'webhook_url', label: 'Slack Webhook URL', placeholder: 'https://hooks.slack.com/services/...', sensitive: true }],
    discord: [{ key: 'webhook_url', label: 'Discord Webhook URL', placeholder: 'https://discord.com/api/webhooks/...', sensitive: true }],
    telegram: [
        { key: 'bot_token', label: 'Bot Token', placeholder: '123456:ABC-DEF...', sensitive: true },
        { key: 'chat_id', label: 'Chat ID', placeholder: '-1001234567890' },
    ],
    push: [],
}

const currentFields = computed(() => settingsFields[form.type] || [])

watch(() => form.type, () => { form.settings = {} })

const submit = () => form.post(route('channels.store'))
</script>

<template>
    <Head title="Create Channel" />

    <div class="max-w-2xl mx-auto space-y-6">
        <BackLink :href="route('channels.index')" label="Back to Channels" />
        <PageHeader title="Create Notification Channel" description="Set up a new channel to receive alerts." />

        <form @submit.prevent="submit" class="glass p-6 space-y-6">
            <div>
                <label class="block text-sm font-medium text-white mb-2">Name</label>
                <input v-model="form.name" type="text" class="form-input w-full" placeholder="e.g. Team Slack" required />
                <p v-if="form.errors.name" class="text-sm text-red-400 mt-1">{{ form.errors.name }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-white mb-2">Type</label>
                <div class="grid grid-cols-3 sm:grid-cols-6 gap-2">
                    <button v-for="t in types" :key="t.value" type="button" @click="form.type = t.value"
                        :class="['p-3 rounded-lg border text-center text-sm font-medium transition-colors',
                            form.type === t.value ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-white/5 border-white/10 text-slate-400 hover:bg-white/10']">
                        {{ t.label }}
                    </button>
                </div>
                <p v-if="form.errors.type" class="text-sm text-red-400 mt-1">{{ form.errors.type }}</p>
            </div>

            <div v-if="currentFields.length > 0" class="space-y-4">
                <div v-for="field in currentFields" :key="field.key">
                    <label class="block text-sm font-medium text-white mb-2">{{ field.label }}</label>
                    <input v-model="form.settings[field.key]" :type="field.sensitive ? 'password' : 'text'" class="form-input w-full" :placeholder="field.placeholder" />
                    <p v-if="form.errors[`settings.${field.key}`]" class="text-sm text-red-400 mt-1">{{ form.errors[`settings.${field.key}`] }}</p>
                </div>
            </div>

            <p v-if="form.errors.settings" class="text-sm text-red-400">{{ form.errors.settings }}</p>

            <div v-if="form.type === 'push'" class="p-4 rounded-lg bg-white/5 border border-white/10 text-slate-400 text-sm">
                Push notifications will be sent to all team members who have enabled them in their browser. Subscribe via Settings to receive push alerts.
            </div>

            <div class="flex items-center gap-3">
                <button type="button" @click="form.is_active = !form.is_active"
                    :class="['relative inline-flex h-6 w-11 rounded-full transition-colors', form.is_active ? 'bg-emerald-500' : 'bg-slate-600']">
                    <span :class="['inline-block h-5 w-5 transform rounded-full bg-white shadow transition', form.is_active ? 'translate-x-5' : 'translate-x-0']" />
                </button>
                <span class="text-sm text-slate-300">Active</span>
            </div>

            <button type="submit" :disabled="form.processing" class="btn-primary w-full py-3 px-4 disabled:opacity-50">
                {{ form.processing ? 'Creating...' : 'Create Channel' }}
            </button>
        </form>
    </div>
</template>
