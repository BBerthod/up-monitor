<script setup lang="ts">
import Skeleton from 'primevue/skeleton'
</script>

<template>
    <div class="space-y-8">
        <!-- Tier 1: Triage Banner skeleton -->
        <div class="glass overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-white/5">
                <Skeleton width="8rem" height="1rem" class="!bg-zinc-900" />
                <Skeleton width="5rem" height="0.75rem" class="!bg-zinc-900" />
            </div>
            <div v-for="i in 2" :key="i" class="flex items-start gap-3 py-3 px-4 border-b border-white/[0.03] last:border-0">
                <Skeleton shape="circle" width="0.5rem" height="0.5rem" class="!bg-zinc-900 mt-2 flex-shrink-0" />
                <Skeleton shape="circle" width="1.75rem" height="1.75rem" class="!bg-zinc-900 flex-shrink-0" />
                <div class="flex-1 space-y-2">
                    <Skeleton width="70%" height="0.875rem" class="!bg-zinc-900" />
                    <Skeleton width="40%" height="0.75rem" class="!bg-zinc-900" />
                </div>
            </div>
        </div>

        <!-- Tier 2: Domain health cards skeleton -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div v-for="i in 4" :key="i" class="glass p-5 space-y-4">
                <Skeleton width="5rem" height="0.75rem" class="!bg-zinc-900" />
                <Skeleton width="4rem" height="2rem" class="!bg-zinc-900" />
                <Skeleton width="6rem" height="0.75rem" class="!bg-zinc-900" />
            </div>
        </div>

        <!-- Tier 3: Portfolio health skeleton -->
        <div class="glass p-6 space-y-4">
            <Skeleton width="8rem" height="1rem" class="!bg-zinc-900" />
            <div v-for="i in 3" :key="i" class="flex items-center gap-4 py-4 border-b border-white/5 last:border-0">
                <Skeleton width="3.5rem" height="3.5rem" borderRadius="0.75rem" class="!bg-zinc-900 flex-shrink-0" />
                <div class="flex-1 space-y-2">
                    <Skeleton width="50%" height="0.875rem" class="!bg-zinc-900" />
                    <Skeleton width="30%" height="0.75rem" class="!bg-zinc-900" />
                </div>
            </div>
        </div>
    </div>
</template>
