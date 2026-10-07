<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

defineOptions({ layout: false })

const features = [
    {
        title: 'HTTP Monitoring',
        text: 'Monitor websites and APIs with status code, keyword and SSL certificate checks.',
        paths: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 4 9 15 15 0 0 1-4 9 15 15 0 0 1-4-9 15 15 0 0 1 4-9Z'],
    },
    {
        title: 'Ping, Port & DNS',
        text: 'ICMP ping, TCP port and DNS record checks for complete infrastructure coverage.',
        paths: ['M5 12h14M12 5l7 7-7 7'],
    },
    {
        title: 'Instant Alerts',
        text: 'Get notified via email, Slack, Discord, Telegram or webhook when something goes wrong.',
        paths: ['M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9ZM13.73 21a2 2 0 0 1-3.46 0'],
    },
    {
        title: 'Status Pages',
        text: 'Public status pages to keep your users informed about service availability.',
        paths: ['M4 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5Z', 'M4 9h16M8 4v5'],
    },
]
</script>

<template>
    <Head title="Up by Radiank" />

    <div class="min-h-screen bg-page flex flex-col">
        <main class="flex-1 flex flex-col justify-center w-full max-w-3xl mx-auto px-4 sm:px-6 py-16">
            <!-- Logo -->
            <div class="flex items-center gap-2 mb-10">
                <span class="flex items-center justify-center w-8 h-8 rounded-md border border-zinc-800 bg-zinc-950">
                    <svg class="w-4 h-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path d="M12 19V5M5 12l7-7 7 7" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="text-xl font-semibold text-zinc-100">Up <span class="text-sm font-normal text-zinc-400">by Radiank</span></span>
            </div>

            <!-- Hero -->
            <h1 class="text-2xl sm:text-[32px] sm:leading-10 font-semibold text-zinc-100 max-w-xl">
                Open-source uptime monitoring
            </h1>
            <p class="mt-4 text-base text-zinc-400 max-w-xl">
                Monitor your websites, APIs and services. Get instant alerts when things go down. Self-hosted and free.
            </p>

            <!-- CTA -->
            <div class="mt-8 flex flex-col sm:flex-row gap-3">
                <Link :href="route('login')" class="btn-primary">
                    Sign in
                </Link>
                <a
                    href="https://github.com/BBerthod/up"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn-secondary"
                >
                    View on GitHub
                </a>
            </div>

            <!-- Features -->
            <section class="mt-16" aria-labelledby="features-title">
                <h2 id="features-title" class="section-label mb-3">What it watches</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 border border-zinc-800 rounded-md divide-y sm:divide-y-0 divide-zinc-800 bg-zinc-950 overflow-hidden">
                    <div
                        v-for="(f, i) in features"
                        :key="f.title"
                        :class="['p-5', i % 2 === 0 && 'sm:border-r sm:border-zinc-800', i < 2 && 'sm:border-b sm:border-zinc-800']"
                    >
                        <div class="flex items-center gap-2 mb-2">
                            <svg class="w-4 h-4 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path v-for="d in f.paths" :key="d" :d="d" />
                            </svg>
                            <h3 class="text-sm font-medium text-zinc-100">{{ f.title }}</h3>
                        </div>
                        <p class="text-sm text-zinc-400">{{ f.text }}</p>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="border-t border-zinc-800">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 py-5 flex items-center justify-between gap-4 text-sm text-zinc-400">
                <a
                    href="https://radiank.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 hover:text-zinc-100 transition-colors"
                >
                    <span>by</span>
                    <svg class="w-4 h-4" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect width="32" height="32" rx="6" fill="#10b981"/>
                        <path fill="#0b0c0e" d="M8 6h8c4.4 0 7 2.6 7 6.5S20.4 19 16 19l6 7h-4.5L12 19v-3h4c2.8 0 4.5-1.5 4.5-3.5S18.8 9 16 9h-4.5v17H8V6z"/>
                    </svg>
                    <span class="font-medium">Radiank</span>
                </a>
                <a
                    href="https://github.com/BBerthod/up"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="hover:text-zinc-100 transition-colors"
                >
                    GitHub
                </a>
            </div>
        </footer>
    </div>
</template>
