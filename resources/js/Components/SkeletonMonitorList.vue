<script setup lang="ts">
import Skeleton from 'primevue/skeleton'
</script>

<template>
    <div class="flex flex-col">
        <div v-for="i in 5" :key="i" class="flex items-center justify-between py-4 border-b border-white/5 px-2">
            <div class="flex items-center gap-4 min-w-0">
                <Skeleton shape="circle" size="0.625rem" class="!bg-zinc-900 shrink-0" />
                <div class="space-y-2">
                    <Skeleton width="8rem" height="0.875rem" class="!bg-zinc-900" />
                    <Skeleton width="12rem" height="0.625rem" class="!bg-zinc-900" />
                </div>
            </div>
            <div class="flex items-center gap-8 md:gap-12 shrink-0 ml-4">
                <div class="hidden md:block">
                    <Skeleton width="3rem" height="0.875rem" class="!bg-zinc-900" />
                </div>
                <div class="hidden sm:block">
                    <Skeleton width="2.5rem" height="0.875rem" class="!bg-zinc-900" />
                </div>
                <Skeleton width="2rem" height="0.75rem" class="!bg-zinc-900" />
                <Skeleton width="0.75rem" height="0.75rem" class="!bg-zinc-900" />
            </div>
        </div>
    </div>
</template>
