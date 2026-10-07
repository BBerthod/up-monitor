<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

withDefaults(defineProps<{
    href: string
    label?: string
}>(), {
    label: 'Back',
})
</script>

<template>
    <Link :href="href" class="inline-flex items-center gap-2 text-sm text-zinc-400 hover:text-zinc-100 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        {{ label }}
    </Link>
</template>
