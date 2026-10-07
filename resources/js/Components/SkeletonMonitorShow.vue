<script setup lang="ts">
import Skeleton from 'primevue/skeleton'
</script>

<template>
    <div class="space-y-6">
        <Skeleton width="8rem" height="1.5rem" class="!bg-zinc-900" />

        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="space-y-3">
                <div class="flex items-center gap-3">
                    <Skeleton width="12rem" height="2rem" class="!bg-zinc-900" />
                    <Skeleton width="4rem" height="1.5rem" class="!bg-zinc-900" />
                </div>
                <Skeleton width="20rem" height="1rem" class="!bg-zinc-900" />
            </div>
            <div class="flex items-center gap-3">
                <Skeleton width="4rem" height="2.5rem" class="!bg-zinc-900" />
                <Skeleton width="5rem" height="2.5rem" class="!bg-zinc-900" />
                <Skeleton width="5rem" height="2.5rem" class="!bg-zinc-900" />
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div v-for="i in 4" :key="i" class="glass p-4 space-y-2">
                <Skeleton width="4rem" height="0.75rem" class="!bg-zinc-900" />
                <Skeleton width="5rem" height="1.5rem" class="!bg-zinc-900" />
            </div>
        </div>

        <div class="glass p-6 space-y-4">
            <Skeleton width="10rem" height="1.25rem" class="!bg-zinc-900" />
            <Skeleton width="100%" height="12rem" class="!bg-zinc-900" />
        </div>

        <div class="glass p-6 space-y-4">
            <Skeleton width="12rem" height="1.25rem" class="!bg-zinc-900" />
            <div class="grid grid-cols-12 gap-2">
                <div v-for="i in 365" :key="i" class="aspect-square">
                    <Skeleton shape="square" class="!bg-zinc-900 w-full h-full" />
                </div>
            </div>
        </div>

        <div class="glass p-6 space-y-4">
            <Skeleton width="10rem" height="1.25rem" class="!bg-zinc-900" />
            <div class="grid grid-cols-4 gap-4">
                <div v-for="i in 4" :key="i" class="text-center space-y-2">
                    <Skeleton width="3rem" height="2rem" class="!bg-zinc-900 mx-auto" />
                    <Skeleton width="2.5rem" height="0.625rem" class="!bg-zinc-900 mx-auto" />
                </div>
            </div>
        </div>

        <div class="glass p-6 space-y-4">
            <Skeleton width="8rem" height="1.25rem" class="!bg-zinc-900" />
            <div class="space-y-3">
                <div v-for="i in 3" :key="i" class="flex items-center justify-between py-3 border-b border-white/5">
                    <div class="flex items-center gap-3">
                        <Skeleton width="5rem" height="1.5rem" class="!bg-zinc-900" />
                    </div>
                    <div class="flex items-center gap-8">
                        <Skeleton width="6rem" height="0.875rem" class="!bg-zinc-900" />
                        <Skeleton width="5rem" height="0.875rem" class="!bg-zinc-900" />
                        <Skeleton width="4rem" height="0.875rem" class="!bg-zinc-900" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
