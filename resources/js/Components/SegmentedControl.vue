<script setup lang="ts">
// Rectangular segmented control for period pickers (response-time chart,
// Lighthouse history, ...). Replaces the pill-button row: a single hairline
// container with a sober active marker, no saturated colour — the direction
// applied across the console redesign (colour flags a problem, not a state).
defineProps<{
    options: string[]
    modelValue: string
}>()

defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
    <div role="radiogroup" class="inline-flex items-center gap-0.5 rounded-md border border-white/10 bg-white/[0.02] p-0.5">
        <button
            v-for="option in options"
            :key="option"
            type="button"
            role="radio"
            :aria-checked="modelValue === option"
            @click="$emit('update:modelValue', option)"
            :class="[
                'rounded-[4px] px-3 py-1.5 text-sm font-medium font-mono transition-colors focus:outline-none focus-visible:ring-1 focus-visible:ring-white/30',
                modelValue === option ? 'bg-white/10 text-white' : 'text-slate-400 hover:text-white hover:bg-white/5',
            ]"
        >
            {{ option }}
        </button>
    </div>
</template>
