<script setup lang="ts">
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import type { SiteScope } from '@/Types/page'

const page = usePage()

const siteScope = computed((): SiteScope | null => (page.props as any).siteScope ?? null)

const isFiltered = computed(() =>
    siteScope.value !== null && siteScope.value.mode !== 'all'
)

const isUnassigned = computed(() => siteScope.value?.mode === 'unassigned')
const isSite = computed(() => siteScope.value?.mode === 'site')

const label = computed(() => {
    if (isSite.value && siteScope.value?.site) {
        return `Scoped to ${siteScope.value.site.name}`
    }
    return 'Showing unassigned items'
})

// Navigate to current page with ?site=all to clear the scope
const viewAll = () => {
    const currentUrl = window.location.pathname
    router.visit(currentUrl + '?site=all')
}
</script>

<template>
    <div
        v-if="isFiltered"
        :class="[
            'flex items-center justify-between gap-3 px-4 py-2 mb-6 rounded-lg border text-xs',
            isUnassigned
                ? 'bg-amber-500/5 border-amber-500/20 text-amber-300'
                : 'bg-emerald-500/5 border-emerald-500/20 text-emerald-300',
        ]"
        role="status"
        aria-live="polite"
    >
        <div class="flex items-center gap-2 min-w-0">
            <!-- Scope icon -->
            <svg
                class="w-3.5 h-3.5 shrink-0"
                :class="isUnassigned ? 'text-amber-400' : 'text-emerald-400'"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            >
                <circle cx="12" cy="12" r="10"/>
                <line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
            <span class="truncate font-medium">{{ label }}</span>
        </div>

        <button
            @click="viewAll"
            :class="[
                'shrink-0 font-medium underline underline-offset-2 hover:no-underline transition-all',
                isUnassigned ? 'text-amber-300 hover:text-amber-200' : 'text-emerald-300 hover:text-emerald-200',
            ]"
            aria-label="Clear site filter and view all"
        >
            View all
        </button>
    </div>
</template>
