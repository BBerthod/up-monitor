import { ref, computed, onMounted } from 'vue'

function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4)
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
    return Uint8Array.from(raw, c => c.charCodeAt(0))
}

function bufferToBase64(buffer: ArrayBuffer | null): string {
    if (!buffer) return ''
    return btoa(String.fromCharCode(...new Uint8Array(buffer)))
}

function getCsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '')
}

export function usePushNotifications() {
    const isSubscribed = ref(false)
    const isLoading = ref(false)
    const error = ref<string | null>(null)

    const isSupported = computed(() =>
        'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
    )

    const headers = () => ({
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-XSRF-TOKEN': getCsrfToken(),
    })

    async function checkSubscription() {
        if (!isSupported.value) return
        try {
            const reg = await navigator.serviceWorker.ready
            const sub = await reg.pushManager.getSubscription()
            isSubscribed.value = !!sub
        } catch { isSubscribed.value = false }
    }

    async function subscribe(): Promise<boolean> {
        if (!isSupported.value) return false
        isLoading.value = true
        error.value = null

        try {
            const permission = await Notification.requestPermission()
            if (permission !== 'granted') { error.value = 'Permission denied'; return false }

            const reg = await navigator.serviceWorker.ready
            const vapidKey = import.meta.env.VITE_VAPID_PUBLIC_KEY
            if (!vapidKey) throw new Error('VAPID key not configured')

            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidKey),
            })

            const res = await fetch('/api/push-subscriptions', {
                method: 'POST',
                headers: headers(),
                body: JSON.stringify({
                    endpoint: sub.endpoint,
                    keys: { p256dh: bufferToBase64(sub.getKey('p256dh')), auth: bufferToBase64(sub.getKey('auth')) },
                }),
            })

            if (!res.ok) throw new Error('Failed to save subscription')
            isSubscribed.value = true
            return true
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Subscription failed'
            return false
        } finally { isLoading.value = false }
    }

    async function unsubscribe(): Promise<boolean> {
        if (!isSupported.value) return false
        isLoading.value = true

        try {
            const reg = await navigator.serviceWorker.ready
            const sub = await reg.pushManager.getSubscription()
            if (sub) {
                const endpoint = sub.endpoint
                await sub.unsubscribe()
                await fetch('/api/push-subscriptions', { method: 'DELETE', headers: headers(), body: JSON.stringify({ endpoint }) })
            }
            isSubscribed.value = false
            return true
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Unsubscribe failed'
            return false
        } finally { isLoading.value = false }
    }

    onMounted(checkSubscription)

    return { isSupported, isSubscribed, isLoading, error, subscribe, unsubscribe }
}
