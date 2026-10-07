import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { ZiggyVue } from 'ziggy-js'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import MyPreset from './primevue-presets'
import AppLayout from './Layouts/AppLayout.vue'
import '@/Types/page'

// Echo + Pusher are lazy-loaded by useEcho composable in AppLayout
// to avoid shipping ~200 KB to unauthenticated / public pages.

createInertiaApp({
    title: (title) => title ? `${title} - Up by Radiank` : 'Up by Radiank',

    resolve: async (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue')
        const page = await pages[`./Pages/${name}.vue`]() as { default: any }
        page.default.layout = page.default.layout ?? AppLayout
        return page
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(ToastService)
            .use(PrimeVue, {
                theme: {
                    preset: MyPreset,
                    options: {
                        darkModeSelector: '.dark',
                    }
                }
            })
            .mount(el)
    },

    progress: {
        color: '#10b981',
    },
})
