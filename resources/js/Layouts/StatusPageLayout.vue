<script setup lang="ts">
import { computed } from 'vue'

// Inertia hands every page prop to the persistent layout; only the theme and timestamp are used here.
const props = defineProps<{
    theme?: 'dark' | 'light'
    statusPage?: { theme?: 'dark' | 'light' }
    generated_at?: string
}>()

const isLight = computed(() => (props.theme ?? props.statusPage?.theme) === 'light')

const updatedAt = computed(() => {
    if (!props.generated_at) return null
    return new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: 'UTC',
    }).format(new Date(props.generated_at))
})
</script>

<template>
    <div
        class="status-page min-h-screen font-sans antialiased"
        :class="isLight ? 'status-light bg-[#f7f7f8] text-[#18181b]' : 'status-dark bg-[#0b0c0e] text-[#e8e8ea]'"
        :style="{ colorScheme: isLight ? 'light' : 'dark' }"
    >
        <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
            <main>
                <slot />
            </main>

            <footer
                class="mt-12 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 border-t pt-6 text-[13px]"
                :class="isLight ? 'border-[#e4e4e7] text-[#71717a]' : 'border-[#26272c] text-[#6b6b72]'"
            >
                <template v-if="updatedAt">
                    <span>Last updated <time :datetime="generated_at" class="font-mono tabular-nums">{{ updatedAt }} UTC</time></span>
                    <span aria-hidden="true">·</span>
                </template>
                <span>
                    Powered by
                    <span class="font-medium" :class="isLight ? 'text-[#047857]' : 'text-[#10b981]'">Up</span>
                </span>
            </footer>
        </div>
    </div>
</template>
