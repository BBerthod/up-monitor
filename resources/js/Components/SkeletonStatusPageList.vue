<script setup lang="ts">
import Skeleton from 'primevue/skeleton'
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div class="space-y-2">
                <Skeleton width="8rem" height="1.75rem" class="!bg-zinc-900" />
                <Skeleton width="16rem" height="0.875rem" class="!bg-zinc-900" />
            </div>
            <Skeleton width="10rem" height="2.5rem" class="!bg-zinc-900" />
        </div>

        <div class="glass overflow-hidden rounded-xl divide-y divide-white/5">
            <div v-for="i in 4" :key="i" class="p-4">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="space-y-3">
                        <div class="flex items-center gap-3">
                            <Skeleton width="10rem" height="1.25rem" class="!bg-zinc-900" />
                            <Skeleton width="4rem" height="1.25rem" class="!bg-zinc-900" />
                        </div>
                        <div class="flex items-center gap-3">
                            <Skeleton width="8rem" height="0.75rem" class="!bg-zinc-900" />
                            <Skeleton width="2rem" height="0.75rem" class="!bg-zinc-900" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Skeleton width="4rem" height="2rem" class="!bg-zinc-900" />
                        <Skeleton width="4rem" height="2rem" class="!bg-zinc-900" />
                        <Skeleton width="5rem" height="2rem" class="!bg-zinc-900" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
