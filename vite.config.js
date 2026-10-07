import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { resolve } from 'path'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, './resources/js'),
            'ziggy-js': resolve(__dirname, './vendor/tightenco/ziggy'),
        },
    },
    build: {
        rollupOptions: {
            output: {
                // Keep the Aura theme, which PrimeVue loads at startup, out of
                // the app entry. Component implementations stay with their
                // pages: in particular, DataTable must never enter a startup
                // vendor chunk.
                manualChunks: (id) => {
                    if (id.includes('/node_modules/@primevue/themes/aura/')
                        || id.includes('/node_modules/@primeuix/themes/dist/aura/')) {
                        return 'primevue-theme'
                    }
                    if (id.includes('node_modules/@vue/') || id.includes('node_modules/vue/')
                        || id.includes('node_modules/@inertiajs/')) {
                        return 'vue-vendor'
                    }
                    // echo + pusher are lazy-loaded by useEcho composable; no manual chunk needed
                },
            },
        },
    },
})
