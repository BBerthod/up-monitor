<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import TriageItem from './TriageItem.vue'
import type { TriageItemData } from './TriageItem.vue'

// Re-export so consumers can import the type from here directly.
export type { TriageItemData }

const props = withDefaults(defineProps<{
    items: TriageItemData[]
    /** Header label, e.g. "Copilot — webcompare.fr" */
    title?: string
    /** Key used to persist collapsed state in localStorage, unique per usage site. */
    storageKey?: string
}>(), {
    title: 'Copilot',
    storageKey: 'ctx-insights.default',
})

const emit = defineEmits<{
    /** Emitted after an item is acknowledged/snoozed so the parent page can reload props. */
    changed: []
}>()

// ─── Visibility ──────────────────────────────────────────────────────────────
// Disappears entirely (v-if) when empty — no empty-state chrome.
const hasItems = computed(() => props.items.length > 0)

// ─── Collapsed state ─────────────────────────────────────────────────────────
const collapsed = ref(false)

onMounted(() => {
    try {
        const stored = localStorage.getItem(props.storageKey)
        if (stored !== null) collapsed.value = stored === '1'
    } catch { /* localStorage may be unavailable */ }
})

function toggle() {
    collapsed.value = !collapsed.value
    try {
        localStorage.setItem(props.storageKey, collapsed.value ? '1' : '0')
    } catch { /* noop */ }
}

// ─── Item cap (max 5 per spec) ───────────────────────────────────────────────
const visibleItems = computed(() => props.items.slice(0, 5))

// ─── Header pill severity ────────────────────────────────────────────────────
const criticalCount = computed(() => props.items.filter(i => i.severity === 'critical').length)
const headerPillClass = computed(() =>
    criticalCount.value > 0
        ? 'bg-red-500/15 text-red-400 border-red-500/20'
        : 'bg-amber-500/15 text-amber-400 border-amber-500/20',
)

function onAcknowledged() {
    emit('changed')
}
</script>

<template>
    <div v-if="hasItems" class="rounded-xl bg-[#111113] border border-white/5 overflow-hidden">
        <!-- Collapsible header -->
        <button
            type="button"
            class="w-full flex items-center justify-between px-4 py-3 border-b border-white/5 text-left hover:bg-white/[0.02] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/50"
            :aria-expanded="!collapsed"
            @click="toggle"
        >
            <div class="flex items-center gap-2">
                <!-- Minimal robot icon -->
                <svg class="w-4 h-4 text-zinc-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M12 11V6"/>
                    <circle cx="12" cy="4" r="2"/>
                    <line x1="8" y1="15" x2="8" y2="15.01" stroke-width="2.5" stroke-linecap="round"/>
                    <line x1="16" y1="15" x2="16" y2="15.01" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
                <span class="text-sm font-semibold text-white">{{ title }}</span>
                <!-- Severity count pill -->
                <span
                    class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded text-[10px] font-bold border"
                    :class="headerPillClass"
                >{{ items.length }}</span>
                <span class="text-xs text-zinc-500 font-light hidden sm:inline">
                    insight{{ items.length === 1 ? '' : 's' }}
                </span>
            </div>
            <!-- Chevron rotates when open -->
            <svg
                class="w-4 h-4 text-zinc-500 transition-transform duration-200 shrink-0"
                :class="{ 'rotate-180': !collapsed }"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>

        <!-- Item list -->
        <div v-if="!collapsed" class="divide-y divide-white/[0.03]">
            <TriageItem
                v-for="item in visibleItems"
                :key="item.id"
                :item="item"
                @acknowledged="onAcknowledged"
            />
            <!-- Overflow notice when list capped at 5 -->
            <div v-if="items.length > 5" class="px-4 py-2 flex items-center gap-1.5 text-xs text-zinc-600">
                <span aria-hidden="true">&#8230;</span>
                <span>{{ items.length - 5 }} more in inbox</span>
            </div>
        </div>
    </div>
</template>
