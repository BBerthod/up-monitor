<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import type { SiteScope } from '@/Types/page'

const page = usePage()
const siteScope = computed((): SiteScope | null => (page.props as any).siteScope ?? null)
// Only show when a specific site is scoped — not on 'all' or 'unassigned'
const showNote = computed(() => siteScope.value?.mode === 'site')
</script>

<template>
    <div
        v-if="showNote"
        class="flex items-center gap-2 px-4 py-2 mb-6 rounded-lg border border-white/5 bg-zinc-800/30 text-xs text-zinc-500"
        role="note"
        aria-label="This section is not filtered by the site scope"
    >
        <svg class="w-3.5 h-3.5 shrink-0 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span>This section is global — not affected by the site filter.</span>
    </div>
</template>
