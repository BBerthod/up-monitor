<script setup lang="ts">
/**
 * Icon — inline SVG glyphs in the Lucide style used across the app
 * (24px grid, 2px stroke, round caps). Replaces the `pi pi-*` classes,
 * whose stylesheet is not loaded. Size and colour come from the caller's
 * classes (default 16px, currentColor).
 */
export type IconName =
    | 'plus' | 'x' | 'check' | 'pencil' | 'trash' | 'external-link'
    | 'chevron-left' | 'chevron-right' | 'server' | 'user' | 'cog' | 'log-out'
    | 'sort' | 'sort-asc' | 'sort-desc'

withDefaults(defineProps<{ name: IconName; size?: number }>(), { size: 16 })
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="shrink-0"
    >
        <template v-if="name === 'plus'"><path d="M12 5v14M5 12h14" /></template>
        <template v-else-if="name === 'x'"><path d="M18 6 6 18M6 6l12 12" /></template>
        <template v-else-if="name === 'check'"><path d="M20 6 9 17l-5-5" /></template>
        <template v-else-if="name === 'pencil'"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></template>
        <template v-else-if="name === 'trash'"><path d="M3 6h18" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></template>
        <template v-else-if="name === 'external-link'"><path d="M15 3h6v6" /><path d="M10 14 21 3" /><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /></template>
        <template v-else-if="name === 'chevron-left'"><path d="m15 18-6-6 6-6" /></template>
        <template v-else-if="name === 'chevron-right'"><path d="m9 18 6-6-6-6" /></template>
        <template v-else-if="name === 'server'"><rect x="2" y="2" width="20" height="8" rx="2" /><rect x="2" y="14" width="20" height="8" rx="2" /><path d="M6 6h.01M6 18h.01" /></template>
        <template v-else-if="name === 'user'"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></template>
        <template v-else-if="name === 'cog'"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" /><circle cx="12" cy="12" r="3" /></template>
        <template v-else-if="name === 'log-out'"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" /></template>
        <template v-else-if="name === 'sort'"><path d="m21 16-4 4-4-4" /><path d="M17 20V4" /><path d="m3 8 4-4 4 4" /><path d="M7 4v16" /></template>
        <template v-else-if="name === 'sort-asc'"><path d="m3 8 4-4 4 4" /><path d="M7 4v16" /><path d="M11 12h4M11 16h7M11 20h10" /></template>
        <template v-else-if="name === 'sort-desc'"><path d="m3 16 4 4 4-4" /><path d="M7 20V4" /><path d="M11 4h10M11 8h7M11 12h4" /></template>
    </svg>
</template>
