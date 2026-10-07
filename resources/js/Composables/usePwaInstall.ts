import { ref, computed, onMounted } from 'vue'

const DISMISSED_KEY = 'pwa-install-dismissed'
const DISMISS_DURATION_MS = 7 * 24 * 60 * 60 * 1000 // 7 days

// Module scope, not per-call: the banner is rendered by InstallPrompt but the
// layout also needs to know whether it is showing so it can reserve room for
// it. Refs created inside the composable would give each caller its own state,
// and the layout would never see the banner appear.
const canInstall = ref(false)
const isDismissed = ref(false)
let deferredPrompt: any = null
let listenersBound = false

const showPrompt = computed(() => canInstall.value && !isDismissed.value)

export function usePwaInstall() {
    onMounted(() => {
        if (listenersBound) return
        listenersBound = true

        // Check if dismissed recently
        const dismissed = localStorage.getItem(DISMISSED_KEY)
        if (dismissed && Date.now() - parseInt(dismissed) < DISMISS_DURATION_MS) {
            isDismissed.value = true
            return
        }

        window.addEventListener('beforeinstallprompt', (e: Event) => {
            e.preventDefault()
            deferredPrompt = e
            canInstall.value = true
        })

        window.addEventListener('appinstalled', () => {
            canInstall.value = false
            deferredPrompt = null
        })
    })

    const install = async (): Promise<boolean> => {
        if (!deferredPrompt) return false
        deferredPrompt.prompt()
        const result = await deferredPrompt.userChoice
        deferredPrompt = null
        canInstall.value = false
        return result.outcome === 'accepted'
    }

    const dismiss = () => {
        isDismissed.value = true
        localStorage.setItem(DISMISSED_KEY, Date.now().toString())
    }

    return { canInstall, showPrompt, install, dismiss }
}
