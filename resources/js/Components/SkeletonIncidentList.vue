<script setup lang="ts">
import Skeleton from 'primevue/skeleton'
</script>

<template>
    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div class="space-y-2">
                <Skeleton width="6rem" height="1.75rem" class="!bg-zinc-900" />
                <Skeleton width="12rem" height="0.875rem" class="!bg-zinc-900" />
            </div>
            <Skeleton width="5rem" height="2rem" class="!bg-zinc-900" />
        </div>

        <div class="flex flex-wrap items-center gap-3 pb-6 border-b border-white/5">
            <Skeleton v-for="i in 5" :key="i" width="6rem" height="2.5rem" class="!bg-zinc-900" />
        </div>

        <div class="hidden md:grid grid-cols-12 gap-4 text-xs font-semibold uppercase tracking-wider px-4">
            <div class="col-span-1"><Skeleton width="3rem" height="0.75rem" class="!bg-zinc-900" /></div>
            <div class="col-span-4"><Skeleton width="4rem" height="0.75rem" class="!bg-zinc-900" /></div>
            <div class="col-span-2"><Skeleton width="3rem" height="0.75rem" class="!bg-zinc-900" /></div>
            <div class="col-span-3"><Skeleton width="3rem" height="0.75rem" class="!bg-zinc-900" /></div>
            <div class="col-span-2 text-right"><Skeleton width="4rem" height="0.75rem" class="!bg-zinc-900 ml-auto" /></div>
        </div>

        <div class="space-y-1">
            <div v-for="i in 8" :key="i" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-center py-3 px-4 border-b border-white/5">
                <div class="col-span-1">
                    <Skeleton shape="circle" size="0.625rem" class="!bg-zinc-900" />
                </div>
                <div class="col-span-4 space-y-2">
                    <Skeleton width="10rem" height="0.875rem" class="!bg-zinc-900" />
                    <Skeleton width="3rem" height="0.625rem" class="!bg-zinc-900" />
                </div>
                <div class="col-span-2">
                    <Skeleton width="5rem" height="1.25rem" class="!bg-zinc-900" />
                </div>
                <div class="col-span-3">
                    <Skeleton width="8rem" height="0.875rem" class="!bg-zinc-900" />
                </div>
                <div class="col-span-2 text-right">
                    <Skeleton width="4rem" height="0.875rem" class="!bg-zinc-900 ml-auto" />
                </div>
            </div>
        </div>
    </div>
</template>
