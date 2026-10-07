import { ref, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'

const isNavigating = ref(false)
const navigationId = ref(0)

let startHandler: (() => void) | null = null
let finishHandler: (() => void) | null = null
let subscriberCount = 0

function setupGlobalHandlers() {
    if (startHandler) return

    startHandler = () => {
        isNavigating.value = true
        navigationId.value++
    }

    finishHandler = () => {
        isNavigating.value = false
    }

    router.on('start', startHandler)
    router.on('finish', finishHandler)
}

function teardownGlobalHandlers() {
    if (subscriberCount === 0 && startHandler) {
        router.off('start', startHandler)
        router.off('finish', finishHandler!)
        startHandler = null
        finishHandler = null
    }
}

export function usePageLoading() {
    // Initialise to false when no navigation is in flight to avoid skeleton flash
    const isLoading = ref(isNavigating.value)

    onMounted(() => {
        subscriberCount++
        setupGlobalHandlers()

        // Sync immediately in case a navigation finished before mount
        isLoading.value = isNavigating.value

        const stopStart = router.on('start', () => {
            isLoading.value = true
        })

        const stopFinish = router.on('finish', () => {
            isLoading.value = false
        })

        onUnmounted(() => {
            subscriberCount--
            stopStart()
            stopFinish()
            teardownGlobalHandlers()
        })
    })

    return {
        isLoading,
        isNavigating,
    }
}

export { isNavigating, navigationId }
