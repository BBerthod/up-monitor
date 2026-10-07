<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

interface Rule {
    type: string
    value?: string | number
    passed?: boolean
    message?: string
}

interface FunctionalCheckResult {
    status: 'passed' | 'failed'
    duration_ms: number
    details: Rule[]
    checked_at: string
}

interface FunctionalCheck {
    id: number
    name: string
    url: string
    resolved_url: string
    type: string
    rules: Rule[]
    check_interval: number
    is_enabled: boolean
    last_status: 'passed' | 'failed' | 'pending'
    last_checked_at: string | null
    last_result: FunctionalCheckResult | null
}

const props = defineProps<{
    monitorId: number
    checks: FunctionalCheck[]
}>()

const expanded = ref<number | null>(null)
const showAddForm = ref(false)

const ruleTypes: Record<string, string[]> = {
    content:    ['text_present', 'text_absent', 'min_content_length', 'status_code'],
    redirect:   ['redirects_to', 'https_enforced', 'no_redirect', 'max_hops', 'www_canonical'],
    sitemap:    ['is_valid_xml', 'min_urls', 'urls_accessible', 'track_changes'],
    robots_txt: ['no_disallow_all', 'text_present', 'text_absent', 'track_changes'],
}

const addForm = useForm({
    name: '',
    url: '',
    type: 'content',
    rules: [{ type: 'text_absent', value: 'Fatal error' }] as Rule[],
    check_interval: 60,
})

const addRule = () => addForm.rules.push({ type: 'text_present', value: '' })
const removeRule = (i: number) => addForm.rules.splice(i, 1)

const submitAdd = () => {
    addForm.post(route('monitors.functional-checks.store', props.monitorId), {
        onSuccess: () => { addForm.reset(); showAddForm.value = false },
    })
}

const runNow = (check: FunctionalCheck) => {
    router.post(route('monitors.functional-checks.run-now', [props.monitorId, check.id]), {}, {
        preserveScroll: true,
    })
}

const deleteCheck = (check: FunctionalCheck) => {
    if (! confirm(`Delete "${check.name}"?`)) return
    router.delete(route('monitors.functional-checks.destroy', [props.monitorId, check.id]), {
        preserveScroll: true,
    })
}

const statusColor = (status: string) => ({
    passed:  'text-emerald-400',
    failed:  'text-red-400',
    pending: 'text-slate-400',
})[status] ?? 'text-slate-400'

const statusDot = (status: string) => ({
    passed:  'bg-emerald-400',
    failed:  'bg-red-400',
    pending: 'bg-slate-400',
})[status] ?? 'bg-slate-400'

const ruleNeedsValue = (type: string) => ! ['https_enforced', 'no_redirect', 'track_changes', 'no_disallow_all', 'is_valid_xml'].includes(type)

const typeLabel: Record<string, string> = {
    content: 'Content', redirect: 'Redirect', sitemap: 'Sitemap', robots_txt: 'Robots.txt',
}

const formatCheckedAt = (d: string) => new Date(d).toLocaleString('en-US', {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
})
</script>

<template>
    <div class="space-y-3">
        <!-- Check list -->
        <div v-if="checks.length === 0" class="text-center py-8 text-slate-400 text-sm">
            No functional checks yet. Add one below.
        </div>

        <div v-for="check in checks" :key="check.id" class="glass rounded-lg overflow-hidden">
            <!-- Summary row -->
            <div class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-white/5 transition-colors"
                @click="expanded = expanded === check.id ? null : check.id">
                <span :class="['w-2 h-2 rounded-full flex-shrink-0', statusDot(check.last_status)]"></span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-white truncate">{{ check.name }}</span>
                        <span class="text-xs text-slate-400 bg-white/5 px-1.5 py-0.5 rounded">{{ typeLabel[check.type] }}</span>
                    </div>
                    <div class="text-xs text-slate-400 truncate">{{ check.resolved_url }}</div>
                </div>
                <div class="text-right flex-shrink-0">
                    <div :class="['text-sm font-medium capitalize', statusColor(check.last_status)]">
                        {{ check.last_status }}
                    </div>
                    <div class="text-xs text-slate-500">
                        {{ check.last_checked_at ? formatCheckedAt(check.last_checked_at) : 'Never' }}
                    </div>
                </div>
                <svg class="w-4 h-4 text-slate-400 flex-shrink-0 transition-transform"
                    :class="expanded === check.id ? 'rotate-180' : ''"
                    viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/>
                </svg>
            </div>

            <!-- Expanded details -->
            <div v-if="expanded === check.id" class="border-t border-white/10 px-4 py-3 space-y-3">
                <!-- Last result breakdown -->
                <div v-if="check.last_result">
                    <div class="text-xs font-medium text-slate-300 mb-2">Last result — {{ check.last_result.duration_ms }}ms</div>
                    <div class="space-y-1">
                        <div v-for="(detail, i) in check.last_result.details" :key="i"
                            :class="['flex items-start gap-2 text-xs px-2 py-1.5 rounded',
                                detail.passed ? 'bg-emerald-500/10 text-emerald-300' : 'bg-red-500/10 text-red-300']">
                            <span class="mt-0.5 flex-shrink-0">{{ detail.passed ? '✓' : '✗' }}</span>
                            <span>[{{ detail.rule }}] {{ detail.message }}</span>
                        </div>
                    </div>
                    <div v-if="check.last_result.details.length === 0 && check.last_result.status === 'failed'"
                        class="text-xs text-red-400 px-2 py-1.5 bg-red-500/10 rounded">
                        Error: check failed with exception
                    </div>
                </div>
                <div v-else class="text-xs text-slate-400">No results yet.</div>

                <!-- Actions -->
                <div class="flex items-center gap-2 pt-1">
                    <button @click="runNow(check)"
                        class="text-xs px-2.5 py-1 bg-white/10 hover:bg-white/20 text-white rounded transition-colors">
                        Run now
                    </button>
                    <button @click="deleteCheck(check)"
                        class="text-xs px-2.5 py-1 bg-red-500/10 hover:bg-red-500/20 text-red-400 rounded transition-colors">
                        Delete
                    </button>
                    <span class="text-xs text-slate-500 ml-auto">Every {{ check.check_interval }}min</span>
                </div>
            </div>
        </div>

        <!-- Add check form -->
        <div v-if="showAddForm" class="glass rounded-lg p-4 space-y-4">
            <div class="text-sm font-medium text-white">New functional check</div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Name</label>
                    <input v-model="addForm.name" type="text" placeholder="Page Best Deals"
                        class="w-full bg-white/5 border border-white/10 rounded px-3 py-1.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500/50" />
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">URL (relative or absolute)</label>
                    <input v-model="addForm.url" type="text" placeholder="/best-deals or https://..."
                        class="w-full bg-white/5 border border-white/10 rounded px-3 py-1.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500/50" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Check type</label>
                    <select v-model="addForm.type"
                        class="w-full bg-white/5 border border-white/10 rounded px-3 py-1.5 text-sm text-white focus:outline-none focus:border-emerald-500/50">
                        <option v-for="(label, key) in typeLabel" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Interval (minutes)</label>
                    <input v-model.number="addForm.check_interval" type="number" min="5" max="1440"
                        class="w-full bg-white/5 border border-white/10 rounded px-3 py-1.5 text-sm text-white focus:outline-none focus:border-emerald-500/50" />
                </div>
            </div>

            <!-- Rules -->
            <div>
                <div class="text-xs text-slate-400 mb-2">Rules</div>
                <div class="space-y-2">
                    <div v-for="(rule, i) in addForm.rules" :key="i" class="flex gap-2 items-center">
                        <select v-model="rule.type"
                            class="bg-white/5 border border-white/10 rounded px-2 py-1.5 text-xs text-white focus:outline-none focus:border-emerald-500/50">
                            <option v-for="rt in (ruleTypes[addForm.type] ?? [])" :key="rt" :value="rt">{{ rt }}</option>
                        </select>
                        <input v-if="ruleNeedsValue(rule.type)" v-model="rule.value" type="text" placeholder="value"
                            class="flex-1 bg-white/5 border border-white/10 rounded px-2 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500/50" />
                        <span v-else class="flex-1 text-xs text-slate-500 italic">no value needed</span>
                        <button @click="removeRule(i)" class="text-slate-400 hover:text-red-400 transition-colors text-xs">✕</button>
                    </div>
                </div>
                <button @click="addRule" class="mt-2 text-xs text-emerald-400 hover:text-emerald-300 transition-colors">+ Add rule</button>
            </div>

            <div class="flex gap-2">
                <button @click="submitAdd" :disabled="addForm.processing"
                    class="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm rounded transition-colors disabled:opacity-50">
                    Save check
                </button>
                <button @click="showAddForm = false"
                    class="px-3 py-1.5 bg-white/10 hover:bg-white/20 text-white text-sm rounded transition-colors">
                    Cancel
                </button>
            </div>
        </div>

        <!-- Add button -->
        <button v-if="!showAddForm" @click="showAddForm = true"
            class="w-full py-2 border border-dashed border-white/20 hover:border-white/40 text-slate-400 hover:text-white text-sm rounded-lg transition-colors">
            + Add functional check
        </button>
    </div>
</template>
