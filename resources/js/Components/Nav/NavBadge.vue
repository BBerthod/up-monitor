<script setup lang="ts">
/**
 * NavBadge — compact count pill for nav items.
 *
 * Color logic:
 * - `critical` prop → red-500 (reserved for Overview total only, where we have cross-domain critical data)
 * - default → amber-500 (sections only have total-count, no per-domain critical breakdown yet)
 * - count 0 → hidden
 */
const props = defineProps<{
    count: number
    critical?: boolean
    dot?: boolean   // collapsed sidebar: show as a small dot instead of a number
}>()
</script>

<template>
    <template v-if="count > 0">
        <!-- dot mode (collapsed sidebar) -->
        <span
            v-if="dot"
            :class="['w-2 h-2 rounded-full shrink-0', critical ? 'bg-red-500' : 'bg-amber-500']"
        />
        <!-- pill mode -->
        <span
            v-else
            :class="[
                'inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded font-mono text-[11px] font-medium leading-none',
                critical ? 'bg-red-500/15 text-red-400 border border-red-500/30' : 'bg-amber-500/15 text-amber-400 border border-amber-500/30'
            ]"
        >{{ count > 99 ? '99+' : count }}</span>
    </template>
</template>
