<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import NavBadge from './NavBadge.vue'

const props = defineProps<{
    title: string
    domain: string             // e.g. 'availability' — used as localStorage key + aria
    badge?: number
    collapsed?: boolean        // sidebar w-20 mode
    advancedHrefs?: string[]   // list of hrefs that live in the "Advanced" sub-block
}>()

const page = usePage()

// Is any item in the advanced slot currently active?
const advancedActive = computed(() => {
    if (!props.advancedHrefs?.length) return false
    return props.advancedHrefs.some(href => page.url.startsWith(href))
})

// Advanced block open state — defaults to closed unless the active page is inside
const advancedKey = computed(() => `nav.advanced.${props.domain}`)
const advancedOpen = ref(false)

onMounted(() => {
    // Always open if the current page is in the advanced block
    if (advancedActive.value) {
        advancedOpen.value = true
        return
    }
    try {
        const stored = localStorage.getItem(advancedKey.value)
        if (stored !== null) advancedOpen.value = stored === 'true'
    } catch {
        // localStorage not available — keep default
    }
})

// Auto-open when navigating to an advanced item
watch(advancedActive, (val) => {
    if (val) advancedOpen.value = true
})

const toggleAdvanced = () => {
    advancedOpen.value = !advancedOpen.value
    try {
        localStorage.setItem(advancedKey.value, String(advancedOpen.value))
    } catch {
        // ignore
    }
}
</script>

<template>
    <div class="space-y-0.5">
        <!-- Section header -->
        <div v-if="!collapsed" class="flex items-center gap-2 px-3 pt-5 pb-1.5">
            <span class="flex-1 section-label select-none">
                {{ title }}
            </span>
            <NavBadge v-if="badge !== undefined" :count="badge" />
        </div>
        <!-- Collapsed: thin separator line -->
        <div v-else class="mx-3 mt-4 mb-1 border-t border-zinc-800" :title="title" />

        <!-- Main items slot -->
        <slot />

        <!-- Advanced collapsible block -->
        <template v-if="$slots.advanced">
            <!-- Toggle button -->
            <button
                v-if="!collapsed"
                @click="toggleAdvanced"
                :aria-expanded="advancedOpen"
                :aria-controls="`nav-advanced-${domain}`"
                class="flex items-center gap-2 w-full px-3 py-1.5 text-xs text-zinc-500 hover:text-zinc-100 transition-colors rounded-md hover:bg-zinc-900 group"
            >
                <svg
                    :class="['w-3 h-3 transition-transform duration-200', advancedOpen ? 'rotate-90' : '']"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                >
                    <path d="M9 18l6-6-6-6"/>
                </svg>
                <span>Advanced</span>
            </button>

            <!-- Thin separator in collapsed mode (advanced always accessible via icon) -->
            <div v-else class="mx-3 my-0.5 border-t border-zinc-800" />

            <!-- Advanced items -->
            <div
                :id="`nav-advanced-${domain}`"
                v-show="advancedOpen || collapsed"
                class="pl-2"
            >
                <slot name="advanced" />
            </div>
        </template>
    </div>
</template>
