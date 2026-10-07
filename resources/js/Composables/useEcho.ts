import { onMounted, onUnmounted } from 'vue'

/**
 * Lazy-loads Laravel Echo + Pusher on first mount.
 * Only call this composable from AppLayout (authenticated routes).
 * After init, window.Echo is available for useRealtimeUpdates.
 */
export function useEcho(): void {
    onMounted(async () => {
        if (!import.meta.env.VITE_REVERB_APP_KEY) return

        // Already initialised (e.g. HMR or second component mount)
        if ((window as any).Echo) return

        const [{ default: Echo }, { default: Pusher }] = await Promise.all([
            import('laravel-echo'),
            import('pusher-js'),
        ])

        ;(window as any).Pusher = Pusher
        ;(window as any).Echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        })
    })

    onUnmounted(() => {
        // AppLayout is only unmounted on full page unload — disconnect cleanly
        ;(window as any).Echo?.disconnect()
        ;(window as any).Echo = undefined
    })
}
