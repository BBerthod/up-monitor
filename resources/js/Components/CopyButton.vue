<script setup lang="ts">
import { ref, computed } from 'vue'

const props = withDefaults(defineProps<{
    text: string
    size?: 'sm' | 'md'
}>(), {
    size: 'sm',
})

const copied = ref(false)

const copy = async () => {
    try {
        await navigator.clipboard.writeText(props.text)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2000)
    } catch {
        // Fallback silently
    }
}

const iconSize = computed(() => props.size === 'md' ? 'w-5 h-5' : 'w-4 h-4')
const buttonPad = computed(() => props.size === 'md' ? 'p-2' : 'p-1.5')
</script>

<template>
    <button
        @click="copy"
        class="rounded-md text-zinc-500 hover:text-white hover:bg-white/10 transition-all duration-150"
        :class="buttonPad"
        :aria-label="copied ? 'Copied!' : 'Copy to clipboard'"
    >
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0 scale-75"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-75"
            mode="out-in"
        >
            <svg v-if="copied" key="check" :class="[iconSize, 'text-emerald-400']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <svg v-else key="clipboard" :class="iconSize" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
        </Transition>
    </button>
</template>
