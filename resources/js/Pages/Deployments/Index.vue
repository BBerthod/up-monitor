<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Icon from '@/Components/Icon.vue'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'

interface SmokeTestResult {
    url: string
    passed: boolean
    status_code: number | null
    response_time_ms: number | null
    error: string | null
}

interface DeployEvent {
    id: number
    application_id: string
    application_name: string
    commit_sha: string | null
    deployed_at: string
    smoke_test_status: 'pending' | 'passed' | 'failed'
    smoke_test_results: SmokeTestResult[] | null
    rollback_triggered: boolean
}

interface SmokeTestConfig {
    id: number
    application_id: string
    application_name: string
    tests: Array<{
        url: string
        expected_status: number
        expected_keyword?: string
        timeout_ms?: number
    }>
    is_active: boolean
}

interface PaginatedDeployEvents {
    data: DeployEvent[]
    links: { url: string | null; label: string; active: boolean }[]
    current_page: number
    last_page: number
}

const props = defineProps<{
    deployEvents: PaginatedDeployEvents
    smokeTestConfigs: SmokeTestConfig[]
    applicationIds: string[]
    filters: Record<string, string>
}>()

const filterApplicationId = ref(props.filters.application_id ?? '')
const filterStatus = ref(props.filters.status ?? '')

const applyFilters = () => {
    router.get(route('deployments.index'), {
        application_id: filterApplicationId.value || undefined,
        status: filterStatus.value || undefined,
    }, { preserveState: true, replace: true })
}

const statusOptions = [
    { value: '', label: 'All statuses' },
    { value: 'pending', label: 'Pending' },
    { value: 'passed', label: 'Passed' },
    { value: 'failed', label: 'Failed' },
]

const statusSeverity = (status: string) => {
    if (status === 'passed') return 'success'
    if (status === 'failed') return 'danger'
    return 'secondary'
}

// Config management
const showConfigDialog = ref(false)
const editingConfig = ref<SmokeTestConfig | null>(null)
const configForm = ref({
    application_id: '',
    application_name: '',
    tests: [{ url: '', expected_status: 200, expected_keyword: '', timeout_ms: 5000 }],
    is_active: true,
})

const openNewConfig = () => {
    editingConfig.value = null
    configForm.value = {
        application_id: '',
        application_name: '',
        tests: [{ url: '', expected_status: 200, expected_keyword: '', timeout_ms: 5000 }],
        is_active: true,
    }
    showConfigDialog.value = true
}

const openEditConfig = (config: SmokeTestConfig) => {
    editingConfig.value = config
    configForm.value = {
        application_id: config.application_id,
        application_name: config.application_name,
        tests: config.tests.map(t => ({
            url: t.url,
            expected_status: t.expected_status,
            expected_keyword: t.expected_keyword ?? '',
            timeout_ms: t.timeout_ms ?? 5000,
        })),
        is_active: config.is_active,
    }
    showConfigDialog.value = true
}

const addTest = () => {
    configForm.value.tests.push({ url: '', expected_status: 200, expected_keyword: '', timeout_ms: 5000 })
}

const removeTest = (index: number) => {
    configForm.value.tests.splice(index, 1)
}

const saveConfig = () => {
    router.post(route('deployments.smoke-test-configs.store'), configForm.value, {
        onSuccess: () => { showConfigDialog.value = false },
        preserveScroll: true,
    })
}

const deleteConfig = (config: SmokeTestConfig) => {
    if (confirm(`Delete smoke test config for "${config.application_name}"?`)) {
        router.delete(route('deployments.smoke-test-configs.destroy', config.id), {
            preserveScroll: true,
        })
    }
}

// Results detail dialog
const showResultsDialog = ref(false)
const selectedResults = ref<SmokeTestResult[]>([])

const viewResults = (event: DeployEvent) => {
    selectedResults.value = event.smoke_test_results ?? []
    showResultsDialog.value = true
}

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleString()
}
</script>

<template>
    <Head title="Deploy Gates" />

    <div class="space-y-8">
        <PageHeader title="Deploy Gates" description="Post-deploy smoke tests and automatic rollback history">
            <template #actions>
                <Button label="Add Config" @click="openNewConfig"><template #icon><Icon name="plus" /></template></Button>
            </template>
        </PageHeader>

        <!-- Smoke Test Configs -->
        <div>
            <h2 class="mb-3 text-lg font-semibold text-surface-800 dark:text-surface-100">Smoke Test Configurations</h2>
            <div v-if="smokeTestConfigs.length === 0" class="rounded-xl border border-surface-200 dark:border-surface-700 p-6 text-center text-surface-500">
                No configurations yet. Add one to start monitoring deploys.
            </div>
            <div v-else class="grid gap-3">
                <div
                    v-for="config in smokeTestConfigs"
                    :key="config.id"
                    class="flex items-center justify-between rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 p-4"
                >
                    <div>
                        <div class="font-semibold text-surface-800 dark:text-surface-100">{{ config.application_name }}</div>
                        <div class="text-sm text-surface-500">{{ config.application_id }} — {{ config.tests.length }} test(s)</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Tag :severity="config.is_active ? 'success' : 'secondary'" :value="config.is_active ? 'Active' : 'Inactive'" />
                        <Button text size="small" aria-label="Edit config" @click="openEditConfig(config)"><template #icon><Icon name="pencil" :size="14" /></template></Button>
                        <Button text size="small" severity="danger" aria-label="Delete config" @click="deleteConfig(config)"><template #icon><Icon name="trash" :size="14" /></template></Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-3">
            <Select
                v-model="filterApplicationId"
                :options="[{ value: '', label: 'All applications' }, ...applicationIds.map(id => ({ value: id, label: id }))]"
                option-label="label"
                option-value="value"
                placeholder="All applications"
                class="w-56"
                @change="applyFilters"
            />
            <Select
                v-model="filterStatus"
                :options="statusOptions"
                option-label="label"
                option-value="value"
                placeholder="All statuses"
                class="w-44"
                @change="applyFilters"
            />
        </div>

        <!-- Deploy Events Table -->
        <div class="overflow-x-auto rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-surface-200 dark:border-surface-700 text-left text-surface-500 uppercase tracking-wide text-xs">
                        <th class="px-4 py-3">Application</th>
                        <th class="px-4 py-3">Commit</th>
                        <th class="px-4 py-3">Deployed At</th>
                        <th class="px-4 py-3">Smoke Tests</th>
                        <th class="px-4 py-3">Rollback</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="event in deployEvents.data"
                        :key="event.id"
                        class="border-b border-surface-100 dark:border-surface-700/50 hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors"
                    >
                        <td class="px-4 py-3 font-medium text-surface-800 dark:text-surface-100">
                            {{ event.application_name }}
                            <div class="text-xs text-surface-400">{{ event.application_id }}</div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-surface-500">
                            {{ event.commit_sha ? event.commit_sha.slice(0, 8) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-surface-600 dark:text-surface-300">
                            {{ formatDate(event.deployed_at) }}
                        </td>
                        <td class="px-4 py-3">
                            <Tag :severity="statusSeverity(event.smoke_test_status)" :value="event.smoke_test_status" class="capitalize" />
                        </td>
                        <td class="px-4 py-3">
                            <Tag v-if="event.rollback_triggered" severity="warn" value="Rolled back" />
                            <span v-else class="text-surface-400">—</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="event.smoke_test_results && event.smoke_test_results.length > 0"
                                label="Results"
                                size="small"
                                text
                                @click="viewResults(event)"
                            />
                        </td>
                    </tr>
                    <tr v-if="deployEvents.data.length === 0">
                        <td colspan="6" class="px-4 py-10 text-center text-surface-400">
                            No deploy events yet. Configure a Dokploy webhook to start tracking.
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <!-- Same pattern as NotificationHistory: v-html on a native
                 element. Laravel's paginator labels contain HTML entities
                 (&laquo;/&raquo;), and v-html on a PrimeVue Button replaces
                 the component's rendered innerHTML wholesale while :label
                 shows the entities raw. -->
            <div v-if="deployEvents.last_page > 1" class="flex justify-end gap-1 p-3 border-t border-surface-200 dark:border-surface-700">
                <template v-for="link in deployEvents.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'px-3 py-1 rounded text-sm transition-colors',
                            link.active ? 'bg-emerald-500/20 text-emerald-400' : 'text-zinc-500 hover:text-white hover:bg-white/5',
                        ]"
                        v-html="link.label"
                        preserve-state
                    />
                    <span v-else class="px-3 py-1 text-sm text-zinc-600" v-html="link.label" />
                </template>
            </div>
        </div>
    </div>

    <!-- Config Dialog -->
    <Dialog v-model:visible="showConfigDialog" modal :header="editingConfig ? 'Edit Smoke Test Config' : 'New Smoke Test Config'" class="w-full max-w-2xl">
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Application ID</label>
                    <input v-model="configForm.application_id" class="w-full rounded border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-800 px-3 py-2 text-sm" placeholder="e.g. fr-example-shop" :disabled="!!editingConfig" />
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Application Name</label>
                    <input v-model="configForm.application_name" class="w-full rounded border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-800 px-3 py-2 text-sm" placeholder="e.g. example-shop FR" />
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium">Tests</label>
                    <Button label="Add test" size="small" text @click="addTest"><template #icon><Icon name="plus" :size="14" /></template></Button>
                </div>
                <div class="space-y-2">
                    <div v-for="(test, index) in configForm.tests" :key="index" class="flex gap-2 items-start rounded border border-surface-200 dark:border-surface-700 p-3">
                        <div class="flex-1 grid grid-cols-2 gap-2">
                            <input v-model="test.url" class="col-span-2 w-full rounded border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-800 px-2 py-1.5 text-sm" placeholder="https://example.com/" />
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-surface-400 whitespace-nowrap">Status:</span>
                                <input v-model.number="test.expected_status" type="number" class="w-full rounded border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-800 px-2 py-1.5 text-sm" placeholder="200" />
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-surface-400 whitespace-nowrap">Keyword:</span>
                                <input v-model="test.expected_keyword" class="w-full rounded border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-800 px-2 py-1.5 text-sm" placeholder="optional" />
                            </div>
                        </div>
                        <Button text size="small" severity="danger" aria-label="Remove test" @click="removeTest(index)" :disabled="configForm.tests.length <= 1"><template #icon><Icon name="x" :size="14" /></template></Button>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <input type="checkbox" v-model="configForm.is_active" id="is_active" class="rounded" />
                <label for="is_active" class="text-sm">Active</label>
            </div>
        </div>

        <template #footer>
            <Button label="Cancel" text @click="showConfigDialog = false" />
            <Button label="Save" @click="saveConfig" />
        </template>
    </Dialog>

    <!-- Results Dialog -->
    <Dialog v-model:visible="showResultsDialog" modal header="Smoke Test Results" class="w-full max-w-xl">
        <div class="space-y-2">
            <div
                v-for="(result, index) in selectedResults"
                :key="index"
                class="rounded border p-3"
                :class="result.passed ? 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/20' : 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/20'"
            >
                <div class="flex items-start justify-between">
                    <div class="font-mono text-xs break-all text-surface-700 dark:text-surface-300">{{ result.url }}</div>
                    <Tag :severity="result.passed ? 'success' : 'danger'" :value="result.passed ? 'PASS' : 'FAIL'" class="ml-2 shrink-0" />
                </div>
                <div class="mt-1 flex gap-4 text-xs text-surface-500">
                    <span v-if="result.status_code">HTTP {{ result.status_code }}</span>
                    <span v-if="result.response_time_ms">{{ result.response_time_ms }}ms</span>
                </div>
                <div v-if="result.error" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ result.error }}</div>
            </div>
        </div>
    </Dialog>
</template>
