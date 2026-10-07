<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import NavIcon from './NavIcon.vue'
import NavBadge from './NavBadge.vue'

const props = defineProps<{
    label: string
    href: string
    icon: string
    badge?: number
    badgeCritical?: boolean
    collapsed?: boolean   // sidebar w-20 mode
    onClick?: () => void  // optional callback (mobile: close drawer)
}>()

const page = usePage()
const isActive = computed(() => page.url.startsWith(props.href))
</script>

<template>
    <Link
        :href="href"
        :class="[
            'flex items-center gap-3 px-3 py-1.5 rounded-md text-[13px] font-medium transition-colors duration-150 group relative',
            isActive
                ? 'text-zinc-100 bg-zinc-900'
                : 'text-zinc-400 hover:text-zinc-100 hover:bg-zinc-900',
            collapsed ? 'justify-center px-2' : ''
        ]"
        @click="onClick?.()"
        :title="collapsed ? label : undefined"
        :aria-current="isActive ? 'page' : undefined"
    >
        <NavIcon :name="icon" />

        <span v-if="!collapsed" class="flex-1 truncate">{{ label }}</span>

        <!-- pill badge (expanded) -->
        <NavBadge v-if="!collapsed && badge !== undefined" :count="badge" :critical="badgeCritical" />

        <!-- dot badge (collapsed) — small indicator top-right of the link area -->
        <NavBadge v-if="collapsed && badge !== undefined" :count="badge" :critical="badgeCritical" dot
            class="absolute top-0.5 right-0.5 pointer-events-none" />
    </Link>
</template>
