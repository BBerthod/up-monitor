<script setup lang="ts">
import Skeleton from 'primevue/skeleton'
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div class="space-y-2">
                <Skeleton width="12rem" height="1.75rem" class="!bg-zinc-900" />
                <Skeleton width="20rem" height="0.875rem" class="!bg-zinc-900" />
            </div>
            <Skeleton width="7rem" height="2.5rem" class="!bg-zinc-900" />
        </div>

        <div class="space-y-4">
            <div v-for="i in 5" :key="i" class="glass p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <Skeleton shape="circle" size="0.625rem" class="!bg-zinc-900" />
                        <div class="space-y-2">
                            <Skeleton width="10rem" height="1rem" class="!bg-zinc-900" />
                            <Skeleton width="4rem" height="1.25rem" class="!bg-zinc-900" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Skeleton width="3rem" height="2rem" class="!bg-zinc-900" />
                        <Skeleton width="3rem" height="2rem" class="!bg-zinc-900" />
                        <Skeleton width="4rem" height="2rem" class="!bg-zinc-900" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
